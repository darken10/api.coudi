<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Employee\InviteEmployeeRequest;
use App\Http\Resources\EmployeeInvitationResource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\Employee\EmployeeInvitationService;
use App\Services\Messaging\MessagingManager;
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

    public function index(Company $company, string $employee, MessagingManager $messaging): JsonResponse
    {
        $model = $this->employee($company, $employee);

        if ($model === null) {
            return $this->notFound('Employé introuvable.');
        }

        $items = EmployeeInvitation::query()
            ->where('employee_id', $model->getKey())
            ->latest('created_at')
            ->get();

        return $this->success([
            'items' => EmployeeInvitationResource::collection($items),
            'resend_available_in' => $this->invitations->resendAvailableIn($items->first()),
            'channels' => $messaging->availability(),
        ]);
    }

    public function store(InviteEmployeeRequest $request, Company $company, string $employee): JsonResponse
    {
        $model = $this->employee($company, $employee);

        if ($model === null) {
            return $this->notFound('Employé introuvable.');
        }

        /** @var User $actor */
        $actor = $request->user();
        $phone = $request->filled('phone') ? $request->string('phone')->toString() : null;
        $channel = $request->filled('channel') ? $request->string('channel')->toString() : null;

        $result = $this->invitations->latestFor($model) === null
            ? $this->invitations->invite($company, $model, $actor, $phone ?? $model->phone, $channel ?? 'sms', $request->ip())
            : $this->invitations->resend($company, $model, $actor, $phone, $channel, $request->ip());

        $invitation = $result['invitation'];

        return $this->created(
            ['invitation' => new EmployeeInvitationResource($invitation), 'simulated' => $result['simulated']],
            $invitation->send_status === EmployeeInvitation::SEND_SENT
                ? 'Code d’activation envoyé.'
                : 'L’invitation est créée mais le code n’a pas pu être envoyé.',
        );
    }

    public function destroy(Request $request, Company $company, string $employee, string $invitation): JsonResponse
    {
        $model = EmployeeInvitation::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee)
            ->whereKey($invitation)
            ->first();

        if ($model === null) {
            return $this->notFound('Invitation introuvable.');
        }

        /** @var User $actor */
        $actor = $request->user();
        $this->invitations->revoke($model, $actor, $request->ip());

        return $this->success(new EmployeeInvitationResource($model->refresh()), 'Invitation révoquée.');
    }

    private function employee(Company $company, string $id): ?Employee
    {
        return Employee::query()
            ->where('company_id', $company->getKey())
            ->whereKey($id)
            ->first();
    }
}
