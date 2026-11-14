<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Affectation multiple d'une commande et historique.
 *
 * `orders.assigned_employee_id` reste renseigné pour les écrans existants et
 * pour la compatibilité de la synchronisation : il reflète la première
 * affectation active. La source de vérité, elle, est `order_assignments`.
 */
final class OrderAssignmentService
{
    /**
     * @param  list<string>  $employeeIds
     * @return list<OrderAssignment>
     */
    public function assign(Order $order, array $employeeIds, User $actor): array
    {
        $employeeIds = array_values(array_unique(array_filter($employeeIds)));

        if ($employeeIds === []) {
            throw ValidationException::withMessages([
                'employee_ids' => 'Sélectionnez au moins un employé.',
            ]);
        }

        $employees = Employee::query()
            ->where('company_id', $order->company_id)
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');

        if ($employees->count() !== count($employeeIds)) {
            // Un identifiant d'un autre atelier ne doit jamais être accepté,
            // même s'il existe ailleurs en base.
            throw ValidationException::withMessages([
                'employee_ids' => 'Un employé sélectionné n’appartient pas à cet atelier.',
            ]);
        }

        return DB::transaction(function () use ($order, $employees, $employeeIds, $actor): array {
            $before = OrderAssignment::query()
                ->where('order_id', $order->id)
                ->where('status', OrderAssignment::STATUS_ACTIVE)
                ->pluck('employee_id')
                ->all();

            $assignments = [];

            foreach ($employeeIds as $employeeId) {
                /** @var OrderAssignment|null $assignment */
                $assignment = OrderAssignment::query()
                    ->where('order_id', $order->id)
                    ->where('employee_id', $employeeId)
                    ->first();

                if ($assignment === null) {
                    $assignment = new OrderAssignment([
                        'order_id' => $order->id,
                        'employee_id' => $employeeId,
                    ]);
                    $assignment->company_id = $order->company_id;
                    $assignment->status = OrderAssignment::STATUS_ACTIVE;
                    $assignment->assigned_at = now();
                    $assignment->assigned_by = $actor->id;
                    $assignment->save();
                } elseif ($assignment->status !== OrderAssignment::STATUS_ACTIVE) {
                    // Réaffectation : on réutilise la ligne pour conserver
                    // l'identifiant déjà connu des appareils.
                    $assignment->forceFill([
                        'status' => OrderAssignment::STATUS_ACTIVE,
                        'assigned_at' => now(),
                        'assigned_by' => $actor->id,
                        'removed_at' => null,
                        'removed_by' => null,
                    ])->save();
                }

                $assignments[] = $assignment;
            }

            // Les employés retirés de la sélection sont désaffectés, pas effacés.
            OrderAssignment::query()
                ->where('order_id', $order->id)
                ->where('status', OrderAssignment::STATUS_ACTIVE)
                ->whereNotIn('employee_id', $employeeIds)
                ->get()
                ->each(function (OrderAssignment $assignment) use ($actor): void {
                    $assignment->forceFill([
                        'status' => OrderAssignment::STATUS_REMOVED,
                        'removed_at' => now(),
                        'removed_by' => $actor->id,
                    ])->save();
                });

            $this->syncLegacyColumn($order);
            $this->bumpVisibility(array_values(array_unique(array_merge(
                array_diff($before, $employeeIds),
                array_diff($employeeIds, $before),
            ))));
            $this->publishVisibility($order);

            return $assignments;
        });
    }

    public function remove(Order $order, string $employeeId, User $actor): void
    {
        DB::transaction(function () use ($order, $employeeId, $actor): void {
            $removed = 0;

            OrderAssignment::query()
                ->where('order_id', $order->id)
                ->where('employee_id', $employeeId)
                ->where('status', OrderAssignment::STATUS_ACTIVE)
                ->get()
                ->each(function (OrderAssignment $assignment) use ($actor, &$removed): void {
                    $assignment->forceFill([
                        'status' => OrderAssignment::STATUS_REMOVED,
                        'removed_at' => now(),
                        'removed_by' => $actor->id,
                    ])->save();
                    $removed++;
                });

            $this->syncLegacyColumn($order);

            if ($removed > 0) {
                $this->bumpVisibility([$employeeId]);
            }

            $this->publishVisibility($order);
        });
    }

    /**
     * Fait changer l'empreinte de périmètre des employés dont l'ensemble
     * d'affectations a bougé.
     *
     * Le prochain `sync/status` de leur appareil verra une signature
     * différente et déclenchera une reprise à zéro, seule façon de faire
     * disparaître localement une commande qui n'est plus accessible.
     *
     * @param  list<string>  $employeeIds
     */
    private function bumpVisibility(array $employeeIds): void
    {
        if ($employeeIds === []) {
            return;
        }

        Employee::query()
            ->whereIn('id', $employeeIds)
            ->increment('assignment_revision');
    }

    /** Aligne la colonne historique sur la première affectation active. */
    public function syncLegacyColumn(Order $order): void
    {
        $first = OrderAssignment::query()
            ->where('order_id', $order->id)
            ->where('status', OrderAssignment::STATUS_ACTIVE)
            ->orderBy('assigned_at')
            ->orderBy('created_at')
            ->value('employee_id');

        if ($order->assigned_employee_id !== $first) {
            $order->forceFill(['assigned_employee_id' => $first])->save();
        }
    }

    /**
     * Fait remonter la commande, son client et ses lignes rattachées aux
     * appareils restreints.
     *
     * Sans ce repiquage de révision, une commande ancienne nouvellement
     * affectée resterait sous le curseur de l'employé : il recevrait
     * l'affectation mais jamais la commande qu'elle désigne.
     */
    private function publishVisibility(Order $order): void
    {
        $order->save();

        $order->client?->save();

        foreach (['notes', 'payments', 'measurements'] as $relation) {
            $order->{$relation}()->get()->each(static function ($model): void {
                $model->save();
            });
        }
    }
}
