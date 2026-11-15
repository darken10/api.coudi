<?php

declare(strict_types=1);

namespace App\Services\Employee;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\Messaging\MessageDeliveryException;
use App\Services\Messaging\MessagingManager;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invitation d'un employé par téléphone : création, envoi, renvoi, suivi.
 *
 * Le patron ne choisit jamais le mot de passe. Le compte naît
 * `pending_activation`, ne peut pas se connecter, et ne devient actif qu'après
 * le code (EmployeeActivationService) puis le mot de passe choisi par
 * l'employé lui-même.
 *
 * Inviter de nouveau un employé (renvoi) crée une nouvelle invitation et
 * révoque la précédente : l'ancien code cesse aussitôt de fonctionner.
 */
final class EmployeeInvitationService
{
    public function __construct(
        private readonly MessagingManager $messaging,
        private readonly InvitationEventRecorder $events,
    ) {}

    /**
     * Crée l'invitation et envoie le code. Le résultat de l'envoi est celui
     * du fournisseur : l'invitation peut revenir en `send_failed`.
     *
     * @return array{invitation: EmployeeInvitation, simulated: bool}
     */
    public function invite(
        Company $company,
        Employee $employee,
        User $actor,
        ?string $phone,
        string $channel,
        ?string $ip = null,
    ): array {
        $phone = $this->assertPhone($phone);
        $this->assertChannel($channel);
        $this->assertEmployeeInvitable($employee);

        [$invitation, $code] = DB::transaction(function () use ($company, $employee, $actor, $phone, $channel, $ip): array {
            // Verrou sur l'employé : deux envois simultanés ne produisent
            // qu'une invitation vivante.
            $locked = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

            $this->assertSendLimits($locked);
            $user = $this->resolvePendingUser($locked, $phone);

            $previous = EmployeeInvitation::query()
                ->where('employee_id', $locked->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->get();

            foreach ($previous as $old) {
                $old->forceFill(['revoked_at' => now(), 'ticket_hash' => null])->save();
                $this->events->record($old, 'revoked', $actor->id, $ip, ['reason' => 'superseded']);
            }

            $id = (string) Str::uuid();
            $code = InvitationCode::generate();

            $invitation = EmployeeInvitation::query()->create([
                'id' => $id,
                'company_id' => $company->syncCompanyId(),
                'employee_id' => $locked->getKey(),
                'user_id' => $user->id,
                'phone' => $phone,
                'channel' => $channel,
                'send_status' => EmployeeInvitation::SEND_PENDING,
                'code_hash' => InvitationCode::hash($id, $code),
                'max_attempts' => config()->integer('koudi.invitation.max_attempts', 5),
                'expires_at' => now()->addHours(config()->integer('koudi.invitation.code_ttl_hours', 24)),
                'created_by' => $actor->id,
            ]);

            $this->events->record($invitation, $previous->isEmpty() ? 'created' : 'resent', $actor->id, $ip);

            return [$invitation, $code];
        });

        $simulated = $this->deliver($invitation, $code, $employee, $company, $actor, $ip);

        return ['invitation' => $invitation->refresh(), 'simulated' => $simulated];
    }

    /**
     * Renvoie un code. Sans numéro ni canal fournis, reprend ceux de la
     * dernière invitation (ou de la fiche employé).
     *
     * @return array{invitation: EmployeeInvitation, simulated: bool}
     */
    public function resend(
        Company $company,
        Employee $employee,
        User $actor,
        ?string $phone = null,
        ?string $channel = null,
        ?string $ip = null,
    ): array {
        $last = $this->latestFor($employee);

        return $this->invite(
            $company,
            $employee,
            $actor,
            $phone ?? ($last === null ? $employee->phone : $last->phone),
            $channel ?? ($last === null ? 'sms' : ($last->channel ?? 'sms')),
            $ip,
        );
    }

    public function revoke(EmployeeInvitation $invitation, User $actor, ?string $ip = null): void
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => 'Une invitation déjà utilisée ne peut pas être révoquée.',
            ]);
        }

        $invitation->forceFill(['revoked_at' => now(), 'ticket_hash' => null])->save();
        $this->events->record($invitation, 'revoked', $actor->id, $ip, ['reason' => 'manual']);
    }

    public function latestFor(Employee $employee): ?EmployeeInvitation
    {
        return EmployeeInvitation::query()
            ->where('employee_id', $employee->getKey())
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /**
     * Dernière invitation de chaque employé de l'atelier : l'écran de suivi.
     *
     * @return Collection<int, EmployeeInvitation>
     */
    public function overview(Company $company): Collection
    {
        return EmployeeInvitation::query()
            ->with('employee:id,name,phone,status')
            ->where('company_id', $company->syncCompanyId())
            ->latest('created_at')
            ->get()
            ->unique('employee_id')
            ->filter(fn (EmployeeInvitation $invitation): bool => $invitation->employee !== null)
            ->values();
    }

    /** Délai restant avant qu'un renvoi soit permis, en secondes (0 si permis). */
    public function resendAvailableIn(?EmployeeInvitation $last): int
    {
        if ($last?->created_at === null) {
            return 0;
        }

        $wait = config()->integer('koudi.invitation.resend_seconds', 60);

        return max(0, $wait - (int) $last->created_at->diffInSeconds(now()));
    }

    /** Envoie le message ; ne lève jamais : l'échec est un statut. */
    private function deliver(
        EmployeeInvitation $invitation,
        string $code,
        Employee $employee,
        Company $company,
        User $actor,
        ?string $ip,
    ): bool {
        $channel = (string) $invitation->channel;

        try {
            $sent = $this->messaging->for($channel)->send(
                (string) $invitation->phone,
                $this->message($employee, $company, $code),
            );
        } catch (MessageDeliveryException $exception) {
            $invitation->forceFill([
                'send_status' => EmployeeInvitation::SEND_FAILED,
                'send_error' => $exception->reason,
            ])->save();
            $this->events->record($invitation, 'send_failed', $actor->id, $ip, ['reason' => $exception->reason]);

            Log::warning('Envoi du code d’activation impossible', [
                'invitation' => $invitation->getKey(),
                'channel' => $channel,
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }

        $invitation->forceFill([
            'send_status' => EmployeeInvitation::SEND_SENT,
            'send_error' => null,
            'provider_message_id' => $sent->providerMessageId,
            'sent_at' => now(),
        ])->save();
        $this->events->record($invitation, 'sent', $actor->id, $ip, ['simulated' => $sent->simulated]);

        return $sent->simulated;
    }

    private function message(Employee $employee, Company $company, string $code): string
    {
        $firstName = Str::of((string) $employee->name)->trim()->explode(' ')->first() ?: 'à vous';
        $hours = config()->integer('koudi.invitation.code_ttl_hours', 24);

        return implode("\n", [
            sprintf('Bonjour %s,', $firstName),
            '',
            sprintf('Votre compte employé COUDI a été créé par %s.', $company->name ?: 'votre responsable d’atelier'),
            '',
            sprintf('Votre code d’activation est : %s', $code),
            '',
            sprintf('Ce code est valable pendant %d heures.', $hours),
            '',
            'Installez l’application COUDI, puis saisissez votre numéro de téléphone et ce code pour activer votre compte.',
            '',
            'Ne partagez ce code avec personne.',
        ]);
    }

    private function assertPhone(?string $phone): string
    {
        $normalized = PhoneNumber::normalize($phone);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'phone' => $phone === null || trim($phone) === ''
                    ? 'Le numéro de téléphone de l’employé est obligatoire pour l’inviter.'
                    : 'Ce numéro de téléphone n’est pas valide. Exemple : 70 12 34 56 ou +226 70 12 34 56.',
            ]);
        }

        return $normalized;
    }

    private function assertChannel(string $channel): void
    {
        if (! in_array($channel, MessagingManager::CHANNELS, true)) {
            throw ValidationException::withMessages(['channel' => 'Choisissez l’envoi par SMS ou par WhatsApp.']);
        }

        $availability = $this->messaging->for($channel)->availability();

        if (! $availability->available) {
            throw ValidationException::withMessages([
                'channel' => $availability->reason ?? 'Ce canal d’envoi n’est pas disponible.',
            ]);
        }
    }

    private function assertEmployeeInvitable(Employee $employee): void
    {
        if ($employee->status !== 'active') {
            throw ValidationException::withMessages([
                'employee' => 'Cet employé est désactivé. Réactivez sa fiche avant de l’inviter.',
            ]);
        }
    }

    private function assertSendLimits(Employee $employee): void
    {
        $last = $this->latestFor($employee);
        $wait = $this->resendAvailableIn($last);

        if ($wait > 0) {
            throw ValidationException::withMessages([
                'employee' => sprintf('Un code vient d’être envoyé. Patientez %d secondes avant d’en renvoyer un.', $wait),
            ]);
        }

        $max = config()->integer('koudi.invitation.max_sends_per_day', 5);
        $sentToday = EmployeeInvitation::query()
            ->where('employee_id', $employee->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($sentToday >= $max) {
            throw ValidationException::withMessages([
                'employee' => sprintf('Limite atteinte : %d codes envoyés à cet employé en 24 heures. Réessayez demain.', $max),
            ]);
        }
    }

    /**
     * Compte en attente lié à l'employé.
     *
     * Un numéro déjà porté par un autre compte Coudi (actif ou invité par un
     * autre atelier) est refusé : on ne crée jamais de second compte et on ne
     * rattache pas un compte existant.
     */
    private function resolvePendingUser(Employee $employee, string $phone): User
    {
        $user = $employee->user_id === null ? null : User::query()->find($employee->user_id);

        if ($user !== null && $user->status === User::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'employee' => 'Ce compte employé est déjà activé : il n’y a plus de code à envoyer.',
            ]);
        }

        if ($user !== null && $user->status === User::STATUS_SUSPENDED) {
            throw ValidationException::withMessages([
                'employee' => 'Le compte de cet employé est suspendu.',
            ]);
        }

        $legacy = PhoneNumber::legacy($phone);
        $taken = User::query()
            ->where(fn ($query) => $query
                ->whereIn('phone', array_values(array_unique(array_filter([$phone, $legacy]))))
                ->orWhereIn('whatsapp', array_values(array_unique(array_filter([$phone, $legacy])))))
            ->when($user instanceof User, fn ($query) => $query->whereKeyNot($user?->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'phone' => 'Ce numéro est déjà utilisé par un autre compte COUDI. Utilisez un autre numéro pour cet employé.',
            ]);
        }

        if ($user instanceof User) {
            $user->forceFill(['phone' => $phone, 'name' => $employee->name])->save();

            return $user;
        }

        /** @var User $user */
        $user = User::query()->create([
            'name' => $employee->name,
            'phone' => $phone,
            // Inutilisable : remplacé par le mot de passe que l'employé choisira.
            'password' => Str::random(64),
            'status' => User::STATUS_PENDING_ACTIVATION,
        ]);

        $employee->forceFill(['user_id' => $user->id])->save();

        return $user;
    }
}
