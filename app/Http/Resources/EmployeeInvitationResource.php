<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Invitation vue par le patron. Le code n'y figure jamais.
 *
 * `status` est l'état métier ; `send_status` distingue ce que le fournisseur
 * a répondu (accepté ≠ livré : `delivered_at` reste vide tant que le
 * fournisseur ne signale pas la livraison).
 *
 * @mixin EmployeeInvitation
 */
final class EmployeeInvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = $this->status();
        /** @var Employee|null $employee */
        $employee = $this->relationLoaded('employee') ? $this->employee : null;

        return [
            'id' => $this->getKey(),
            'employee_id' => $this->employee_id,
            'employee_name' => $employee?->name,
            'phone' => $this->phone,
            'phone_display' => $this->phone === null ? null : PhoneNumber::display($this->phone),
            'channel' => $this->channel,
            'status' => $status,
            'send_status' => $this->send_status,
            'send_error' => $this->send_error,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'created_at' => $this->created_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'code_verified_at' => $this->code_verified_at?->toIso8601String(),
            'activated_at' => $this->accepted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'last_activity_at' => $this->updated_at?->toIso8601String(),
            // Le patron peut-il renvoyer un code ? (Les limites de débit restent vérifiées à l'envoi.)
            'can_resend' => ! in_array($status, [EmployeeInvitation::STATUS_ACTIVATED], true),
        ];
    }
}
