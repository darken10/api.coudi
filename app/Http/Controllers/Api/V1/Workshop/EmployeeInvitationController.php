<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workshop;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Requests\Api\V1\Employee\InviteEmployeeRequest;
use App\Http\Resources\EmployeeInvitationResource;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\Employee\EmployeeInvitationService;
use App\Services\Messaging\MessagingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invitations des employés, côté patron (atelier courant via X-Company-Id).
 *
 * Lecture ouverte aux membres de l'atelier ayant accès aux employés ;
 * inviter, renvoyer et révoquer sont réservés aux rôles en écriture.
 */
final class EmployeeInvitationController extends ApiController
{
    use ResolvesTenant;

    public function __construct(private readonly EmployeeInvitationService $invitations) {}

    /** Canaux réellement utilisables, pour l'écran de création. */
    public function channels(MessagingManager $messaging): JsonResponse
    {
        return $this->success($messaging->availability());
    }

    /** Suivi : dernière invitation de chaque employé de l'atelier. */
    public function overview(Request $request): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas de suivre les invitations.');
        }

        return $this->success(EmployeeInvitationResource::collection($this->invitations->overview($this->company($request))));
    }

    /** Historique des invitations d'un employé, la plus récente en premier. */
    public function index(Request $request, string $employee): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas de suivre les invitations.');
        }

        $model = $this->employee($request, $employee);

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
        ]);
    }

    /**
     * Invite l'employé ou lui renvoie un code. La réponse porte le résultat
     * réel de l'envoi : 201 même si le fournisseur a échoué, avec
     * `send_status = failed` — l'invitation existe et peut être renvoyée.
     */
    public function store(InviteEmployeeRequest $request, string $employee): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas d’inviter un employé.');
        }

        $model = $this->employee($request, $employee);

        if ($model === null) {
            return $this->notFound('Employé introuvable.');
        }

        /** @var User $actor */
        $actor = $request->user();
        $phone = $request->filled('phone') ? $request->string('phone')->toString() : null;
        $channel = $request->filled('channel') ? $request->string('channel')->toString() : null;

        $result = $this->invitations->latestFor($model) === null
            ? $this->invitations->invite($this->company($request), $model, $actor, $phone ?? $model->phone, $channel ?? 'sms', $request->ip())
            : $this->invitations->resend($this->company($request), $model, $actor, $phone, $channel, $request->ip());

        $invitation = $result['invitation'];
        $sent = $invitation->send_status === EmployeeInvitation::SEND_SENT;

        return $this->created(
            [
                'invitation' => new EmployeeInvitationResource($invitation),
                'simulated' => $result['simulated'],
            ],
            $sent
                ? sprintf('Code envoyé par %s.', $invitation->channel === 'whatsapp' ? 'WhatsApp' : 'SMS')
                : 'L’invitation est créée mais le code n’a pas pu être envoyé. Vous pouvez réessayer.',
        );
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

        /** @var User $actor */
        $actor = $request->user();
        $this->invitations->revoke($model, $actor, $request->ip());

        return $this->success(new EmployeeInvitationResource($model->refresh()), 'Invitation révoquée.');
    }

    private function employee(Request $request, string $id): ?Employee
    {
        return Employee::query()
            ->where('company_id', $this->company($request)->syncCompanyId())
            ->whereKey($id)
            ->first();
    }
}
