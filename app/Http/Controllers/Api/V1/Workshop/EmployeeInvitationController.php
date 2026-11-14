<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workshop;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Requests\Api\V1\Employee\AcceptInvitationRequest;
use App\Http\Requests\Api\V1\Employee\InviteEmployeeRequest;
use App\Http\Resources\UserResource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\Auth\TokenIssuer;
use App\Services\Employee\EmployeeInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invitation d'un employé, puis activation publique du compte.
 *
 * Le patron crée l'invitation depuis le back-office ; le lien est remis une
 * seule fois. L'activation, elle, se fait sans atelier courant : l'employé
 * n'appartient pas encore à l'application, il ne peut donc fournir aucun
 * en-tête `X-Company-Id`.
 */
final class EmployeeInvitationController extends ApiController
{
    use ResolvesTenant;

    public function __construct(private readonly EmployeeInvitationService $invitations) {}

    public function index(Request $request, string $employee): JsonResponse
    {
        $model = $this->employee($request, $employee);

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
        string $employee,
    ): JsonResponse {
        if (! $this->canWrite($request)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas d’inviter un employé.');
        }

        $model = $this->employee($request, $employee);

        if ($model === null) {
            return $this->notFound('Employé introuvable.');
        }

        /** @var User $actor */
        $actor = $request->user();

        $result = $this->invitations->invite(
            $this->company($request),
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

    public function destroy(Request $request, string $invitation): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas de révoquer une invitation.');
        }

        $model = EmployeeInvitation::query()
            ->where('company_id', $this->company($request)->syncCompanyId())
            ->whereKey($invitation)
            ->first();

        if ($model === null) {
            return $this->notFound('Invitation introuvable.');
        }

        $this->invitations->revoke($model);

        return $this->success(message: 'Invitation révoquée.');
    }

    /** Aperçu public : aucune donnée sensible, aucun mot de passe demandé. */
    public function preview(string $token): JsonResponse
    {
        $invitation = $this->invitations->preview($token);

        /** @var Employee|null $employee */
        $employee = $invitation->employee;
        /** @var Company|null $company */
        $company = $invitation->company;

        return $this->success([
            'employee_name' => $employee?->name,
            'company_name' => $company?->name,
            'email' => $this->maskEmail($invitation->email),
            'phone' => $this->maskPhone($invitation->phone),
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'requires_email' => $invitation->email === null,
            'requires_phone' => $invitation->phone === null,
        ]);
    }

    public function accept(
        AcceptInvitationRequest $request,
        string $token,
        TokenIssuer $tokens,
    ): JsonResponse {
        /** @var array{name: string, password: string, email?: string|null, phone?: string|null} $data */
        $data = $request->validated();

        $user = $this->invitations->accept($token, $data);

        $payload = $tokens->issue($user);
        $payload['user'] = new UserResource($user);

        return $this->success($payload, 'Compte activé. Bienvenue sur Koudi.');
    }

    private function employee(Request $request, string $id): ?Employee
    {
        return Employee::query()
            ->where('company_id', $this->company($request)->syncCompanyId())
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

    private function maskEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($local, 0, 2).'***@'.$domain;
    }

    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        return str_repeat('•', max(0, strlen($phone) - 3)).substr($phone, -3);
    }
}
