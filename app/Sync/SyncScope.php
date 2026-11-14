<?php

declare(strict_types=1);

namespace App\Sync;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Périmètre de visibilité d'un appareil.
 *
 * Un employé dont l'atelier a activé `employee_restricted_orders` ne doit
 * recevoir que les commandes qui lui sont affectées, leurs clients et leurs
 * lignes. Le filtrage vit ici, pas dans le client : un téléphone ne décide
 * jamais de ce qu'il a le droit de voir.
 *
 * Le périmètre est persisté par appareil (`last_scope`). Quand il change —
 * bascule du réglage, changement de fiche employé — la synchronisation repart
 * de zéro et l'appareil purge ses données locales avant d'appliquer le flux.
 */
final class SyncScope
{
    private ?array $orderIds = null;

    private ?array $clientIds = null;

    public function __construct(
        public readonly Company $company,
        public readonly ?string $employeeId,
        public readonly bool $restricted,
        public readonly int $assignmentRevision = 0,
    ) {}

    public static function for(User $user, Company $company): self
    {
        $settings = $company->settings;
        $restricted = (bool) ($settings?->employee_restricted_orders ?? false);

        if (! $restricted) {
            return new self($company, null, false);
        }

        $role = $user->roleIn($company);

        if ($role !== Company::ROLE_TAILOR) {
            return new self($company, null, false);
        }

        $employee = $user->employeeIn($company);

        // Un tailleur sans fiche employé n'a aucune commande à voir : on le
        // traite comme restreint à un périmètre vide, jamais comme « tout ».
        return new self(
            $company,
            $employee?->getKey(),
            true,
            (int) ($employee?->assignment_revision ?? 0),
        );
    }

    /**
     * Empreinte stable du périmètre, stockée sur l'état de synchronisation.
     *
     * Le compteur d'affectation fait partie de l'empreinte : un retrait de
     * commande doit forcer un rechargement complet, sinon la ligne resterait
     * dans le téléphone alors que le serveur ne la renvoie plus.
     */
    public function signature(): string
    {
        return $this->restricted
            ? 'restricted:'.($this->employeeId ?? 'none').':'.$this->assignmentRevision
            : 'full';
    }

    public function isRestricted(): bool
    {
        return $this->restricted;
    }

    /**
     * Applique le filtre de périmètre à la requête d'une entité.
     *
     * @param  Builder<Model>  $query
     */
    public function apply(string $key, Builder $query): void
    {
        if (! $this->restricted) {
            return;
        }

        $orderIds = $this->orderIds();
        $clientIds = $this->clientIds();

        match ($key) {
            'orders' => $query->whereIn('id', $orderIds),
            'clients' => $query->whereIn('id', $clientIds),
            'client_measurements' => $query->whereIn('client_id', $clientIds),
            'order_assignments' => $query->where('employee_id', $this->employeeId),
            'order_notes', 'order_payments', 'order_measurements' => $query->whereIn('order_id', $orderIds),
            'employees' => $query->where('id', $this->employeeId),
            'employee_piece_rates' => $query->where('employee_id', $this->employeeId),
            'work_logs' => $query->where('employee_id', $this->employeeId),
            'salary_payments' => $query->where('employee_id', $this->employeeId),
            'media_assets' => $query->where(function (Builder $media) use ($orderIds, $clientIds): void {
                $media->where(function (Builder $owned) use ($orderIds, $clientIds): void {
                    $owned->where('owner_type', 'order')->whereIn('owner_id', $orderIds);
                })->orWhere(function (Builder $owned) use ($clientIds): void {
                    $owned->where('owner_type', 'client')->whereIn('owner_id', $clientIds);
                })->orWhereNotIn('owner_type', ['order', 'client']);
            }),
            default => null,
        };
    }

    /** @return list<string> */
    public function orderIds(): array
    {
        return $this->orderIds ??= $this->employeeId === null
            ? []
            : OrderAssignment::query()
                ->where('company_id', $this->company->getKey())
                ->where('employee_id', $this->employeeId)
                ->where('status', OrderAssignment::STATUS_ACTIVE)
                ->pluck('order_id')
                ->all();
    }

    /** @return list<string> */
    public function clientIds(): array
    {
        return $this->clientIds ??= $this->orderIds() === []
            ? []
            : Order::query()
                ->where('company_id', $this->company->getKey())
                ->whereIn('id', $this->orderIds())
                ->whereNotNull('client_id')
                ->pluck('client_id')
                ->unique()
                ->values()
                ->all();
    }
}
