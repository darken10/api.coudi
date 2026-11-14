<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Order\AssignOrderRequest;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use App\Services\Order\OrderAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Affectations d'une commande depuis la console d'administration. */
final class OrderAssignmentController extends ApiController
{
    public function __construct(private readonly OrderAssignmentService $assignments) {}

    public function index(Company $company, string $order): JsonResponse
    {
        $model = $this->order($company, $order);

        if ($model === null) {
            return $this->notFound('Commande introuvable.');
        }

        return $this->success($this->rows($model));
    }

    public function store(
        AssignOrderRequest $request,
        Company $company,
        string $order,
    ): JsonResponse {
        $model = $this->order($company, $order);

        if ($model === null) {
            return $this->notFound('Commande introuvable.');
        }

        /** @var User $actor */
        $actor = $request->user();

        /** @var list<string> $employeeIds */
        $employeeIds = $request->validated('employee_ids');

        $this->assignments->assign($model, $employeeIds, $actor);

        return $this->success($this->rows($model), 'Affectations enregistrées.');
    }

    public function destroy(Request $request, Company $company, string $order, string $employee): JsonResponse
    {
        $model = $this->order($company, $order);

        if ($model === null) {
            return $this->notFound('Commande introuvable.');
        }

        /** @var User $actor */
        $actor = $request->user();

        $this->assignments->remove($model, $employee, $actor);

        return $this->success(message: 'Employé désaffecté.');
    }

    private function order(Company $company, string $id): ?Order
    {
        return Order::query()
            ->where('company_id', $company->getKey())
            ->whereKey($id)
            ->first();
    }

    /** @return list<array<string, mixed>> */
    private function rows(Order $order): array
    {
        return OrderAssignment::query()
            ->with('employee:id,name,role,phone')
            ->where('order_id', $order->id)
            ->orderBy('created_at')
            ->get()
            ->map(static fn (OrderAssignment $assignment): array => [
                'id' => $assignment->getKey(),
                'order_id' => $assignment->order_id,
                'employee_id' => $assignment->employee_id,
                'employee' => $assignment->employee?->only(['id', 'name', 'role', 'phone']),
                'status' => $assignment->status,
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                'removed_at' => $assignment->removed_at?->toIso8601String(),
            ])
            ->all();
    }
}
