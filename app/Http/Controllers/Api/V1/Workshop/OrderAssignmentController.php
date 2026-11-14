<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workshop;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Requests\Api\V1\Order\AssignOrderRequest;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use App\Services\Order\OrderAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Affectations d'une commande.
 *
 * La liste est remplacée en bloc : le back-office envoie l'ensemble des
 * employés cochés, et le service calcule les ajouts, réactivations et
 * retraits. Aucune ligne n'est supprimée, l'historique reste lisible.
 */
final class OrderAssignmentController extends ApiController
{
    use ResolvesTenant;

    public function __construct(private readonly OrderAssignmentService $assignments) {}

    public function index(Request $request, string $order): JsonResponse
    {
        $model = $this->order($request, $order);

        if ($model === null) {
            return $this->notFound('Commande introuvable.');
        }

        $items = OrderAssignment::query()
            ->with('employee:id,name,role,phone')
            ->where('order_id', $model->id)
            ->orderBy('created_at')
            ->get();

        return $this->success($items);
    }

    public function store(
        AssignOrderRequest $request,
        string $order,
    ): JsonResponse {
        if (! $this->canWrite($request)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas d’affecter une commande.');
        }

        $model = $this->order($request, $order);

        if ($model === null) {
            return $this->notFound('Commande introuvable.');
        }

        /** @var User $actor */
        $actor = $request->user();

        /** @var list<string> $employeeIds */
        $employeeIds = $request->validated('employee_ids');

        $this->assignments->assign($model, $employeeIds, $actor);

        $items = OrderAssignment::query()
            ->with('employee:id,name,role,phone')
            ->where('order_id', $model->id)
            ->orderBy('created_at')
            ->get();

        return $this->success($items, 'Affectations enregistrées.');
    }

    public function destroy(Request $request, string $order, string $employee): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas de retirer une affectation.');
        }

        $model = $this->order($request, $order);

        if ($model === null) {
            return $this->notFound('Commande introuvable.');
        }

        /** @var User $actor */
        $actor = $request->user();

        $this->assignments->remove($model, $employee, $actor);

        return $this->success(message: 'Employé désaffecté.');
    }

    private function order(Request $request, string $id): ?Order
    {
        return Order::query()
            ->where('company_id', $this->company($request)->syncCompanyId())
            ->whereKey($id)
            ->first();
    }
}
