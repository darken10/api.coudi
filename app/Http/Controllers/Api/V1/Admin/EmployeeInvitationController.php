<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Employee\InviteEmployeeRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\Employee\EmployeeInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invitation d'un employé depuis la console d'administration.
 *
 * Même service que la façade atelier : un seul chemin métier, deux façades
 * d'authentification (membre de l'atelier ou super administrateur).
 */
final class EmployeeInvitationController extends ApiController
{
    public function __construct(private readonly EmployeeInvitationService $invitations) {}

    public function index(Company $company, string $employee): JsonResponse
    {
        $model = $this->employee($company, $employee);

        if ($model === null) {
            return $this->notFound('Employé introuvable.');
        }

        $items = EmployeeInvitation::query()
            ->where('employee_id', $model->id)
            ->latest('created_at')
            ->get()
            ->map(fn (EmployeeInvitation $invitation): array => $this->present($invitation));

        return $this->success($items);
    }

    public function store(
        InviteEmployeeRequest $request,
        Company $company,
        string $employee,
    ): JsonResponse {
        $model = $this->employee($company, $employee);

        if ($model === null) {
            return $this->notFound('Employé introuvable.');
        }

        /** @var User $actor */
        $actor = $request->user();

        $result = $this->invitations->invite(
            $company,
            $model,
            $actor,
            $request->input('email'),
            $request->input('phone'),
        );

        return $this->created([
            'invitation' => $this->present($result['invitation']),
            'token' => $result['token'],
            'activation_url' => $result['url'],
            'deep_link' => $result['deep_link'],
        ], 'Invitation créée. Le lien ne sera plus affiché.');
    }

    public function destroy(Company $company, string $invitation): JsonResponse
    {
        $model = EmployeeInvitation::query()
            ->where('company_id', $company->getKey())
            ->whereKey($invitation)
            ->first();

        if ($model === null) {
            return $this->notFound('Invitation introuvable.');
        }

        $this->invitations->revoke($model);

        return $this->success(message: 'Invitation révoquée.');
    }

    private function employee(Company $company, string $id): ?Employee
    {
        return Employee::query()
            ->where('company_id', $company->getKey())
            ->whereKey($id)
            ->first();
    }

    /** @return array<string, mixed> */
    private function present(EmployeeInvitation $invitation): array
    {
        return [
            'id' => $invitation->getKey(),
            'employee_id' => $invitation->employee_id,
            'email' => $invitation->email,
            'phone' => $invitation->phone,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'revoked_at' => $invitation->revoked_at?->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }
}
