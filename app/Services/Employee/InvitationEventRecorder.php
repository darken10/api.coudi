<?php

declare(strict_types=1);

namespace App\Services\Employee;

use App\Models\EmployeeInvitation;
use App\Models\EmployeeInvitationEvent;

/**
 * Trace les étapes d'une invitation.
 *
 * `meta` ne reçoit que du contexte non sensible (motif d'échec, nombre
 * d'essais…). Les codes, tickets et mots de passe n'y entrent jamais.
 */
final class InvitationEventRecorder
{
    /** @param array<string, scalar|null> $meta */
    public function record(
        EmployeeInvitation $invitation,
        string $type,
        ?int $actorId = null,
        ?string $ip = null,
        array $meta = [],
    ): void {
        EmployeeInvitationEvent::query()->create([
            'invitation_id' => $invitation->getKey(),
            'company_id' => $invitation->company_id,
            'employee_id' => $invitation->employee_id,
            'type' => $type,
            'channel' => $invitation->channel,
            'actor_user_id' => $actorId,
            'ip' => $ip,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
