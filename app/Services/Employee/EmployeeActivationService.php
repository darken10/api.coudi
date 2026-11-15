<?php

declare(strict_types=1);

namespace App\Services\Employee;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Activation d'un compte employé depuis son propre téléphone.
 *
 * Deux temps, et aucun jeton de session entre les deux :
 *  1. `verify` : numéro + code. Consomme le code et remet un ticket à usage
 *     unique, valable quelques minutes.
 *  2. `complete` : ticket + mot de passe. Active le compte ; le contrôleur
 *     émet alors les jetons.
 * Sans mot de passe choisi, il n'existe donc aucun moyen d'obtenir une
 * session — ni par l'application, ni en appelant l'API directement.
 *
 * Un seul message d'échec pour le numéro inconnu, le code faux, expiré,
 * bloqué ou déjà utilisé : l'écran ne révèle pas qu'un numéro a été invité.
 */
final class EmployeeActivationService
{
    public const GENERIC_FAILURE = 'Numéro ou code incorrect, ou code expiré. Vérifiez le message reçu ou demandez un nouveau code à votre responsable d’atelier.';

    public const TICKET_FAILURE = 'Le délai pour choisir votre mot de passe est dépassé. Demandez un nouveau code à votre responsable d’atelier.';

    public function __construct(private readonly InvitationEventRecorder $events) {}

    /**
     * @return array{ticket: string, expires_at: string, employee_name: string|null, company_name: string|null}
     */
    public function verify(?string $phone, ?string $code, ?string $ip = null, ?string $deviceId = null): array
    {
        $phone = PhoneNumber::normalize($phone);
        $code = $code === null ? '' : (string) preg_replace('/\D/', '', $code);

        if ($phone === null || strlen($code) !== 6) {
            throw ValidationException::withMessages(['code' => self::GENERIC_FAILURE]);
        }

        $limiterKey = 'activation-verify:'.$phone;
        $maxPerPhone = config()->integer('koudi.invitation.verify_per_phone', 5);

        if (RateLimiter::tooManyAttempts($limiterKey, $maxPerPhone)) {
            $minutes = (int) ceil(RateLimiter::availableIn($limiterKey) / 60);

            throw ValidationException::withMessages([
                'code' => sprintf('Trop d’essais pour ce numéro. Réessayez dans %d minute%s.', $minutes, $minutes > 1 ? 's' : ''),
            ]);
        }

        $invitation = EmployeeInvitation::query()
            ->where('phone', $phone)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->whereNull('code_verified_at')
            ->latest('created_at')
            ->first();

        if ($invitation === null) {
            $this->fail($limiterKey);
        }

        if (! $invitation->acceptsCode()) {
            $type = match (true) {
                $invitation->expires_at->isPast() => 'code_expired',
                $invitation->attempts >= $invitation->max_attempts => 'code_blocked',
                default => 'code_failed',
            };
            $this->events->record($invitation, $type, ip: $ip, meta: ['reason' => 'not_usable']);
            $this->fail($limiterKey);
        }

        if (! InvitationCode::matches($invitation->id, $code, (string) $invitation->code_hash)) {
            // Incrément atomique : deux saisies simultanées comptent pour deux.
            EmployeeInvitation::query()->whereKey($invitation->getKey())->increment('attempts');
            $attempts = $invitation->attempts + 1;
            $this->events->record($invitation, 'code_failed', ip: $ip, meta: ['attempts' => $attempts]);

            if ($attempts >= $invitation->max_attempts) {
                $this->events->record($invitation, 'code_blocked', ip: $ip);
            }

            $this->fail($limiterKey);
        }

        $ticket = Str::random(64);
        $ticketExpiresAt = now()->addMinutes(config()->integer('koudi.invitation.ticket_ttl_minutes', 30));

        // Consommation conditionnelle : si deux appareils valident le même
        // code au même instant, une seule mise à jour aboutit.
        $consumed = EmployeeInvitation::query()
            ->whereKey($invitation->getKey())
            ->whereNull('code_verified_at')
            ->whereNotNull('code_hash')
            ->whereNull('revoked_at')
            ->update([
                'code_hash' => null,
                'code_verified_at' => now(),
                'ticket_hash' => hash('sha256', $ticket),
                'ticket_expires_at' => $ticketExpiresAt,
                'activation_ip' => $ip,
                'activation_device_id' => $deviceId === null ? null : Str::limit($deviceId, 100, ''),
                'updated_at' => now(),
            ]);

        if ($consumed !== 1) {
            $this->fail($limiterKey);
        }

        RateLimiter::clear($limiterKey);

        User::query()->whereKey($invitation->user_id)->update(['phone_verified_at' => now()]);
        $this->events->record($invitation, 'code_verified', ip: $ip, meta: ['device' => $deviceId !== null]);

        /** @var Employee|null $employee */
        $employee = $invitation->employee;
        /** @var Company|null $company */
        $company = $invitation->company;

        return [
            'ticket' => $ticket,
            'expires_at' => $ticketExpiresAt->toIso8601String(),
            'employee_name' => $employee?->name,
            'company_name' => $company?->name,
        ];
    }

    /** Choix du mot de passe : dernière étape, puis compte actif. */
    public function complete(string $ticket, string $password, ?string $ip = null): User
    {
        $invitation = EmployeeInvitation::query()
            ->where('ticket_hash', hash('sha256', $ticket))
            ->first();

        if (
            $invitation === null
            || $invitation->accepted_at !== null
            || $invitation->revoked_at !== null
            || $invitation->ticket_expires_at === null
            || $invitation->ticket_expires_at->isPast()
        ) {
            throw ValidationException::withMessages(['ticket' => self::TICKET_FAILURE]);
        }

        return DB::transaction(function () use ($invitation, $password, $ip): User {
            // Le ticket ne sert qu'une fois, même en cas d'appels simultanés.
            $claimed = EmployeeInvitation::query()
                ->whereKey($invitation->getKey())
                ->whereNotNull('ticket_hash')
                ->whereNull('accepted_at')
                ->update(['ticket_hash' => null, 'accepted_at' => now(), 'updated_at' => now()]);

            if ($claimed !== 1) {
                throw ValidationException::withMessages(['ticket' => self::TICKET_FAILURE]);
            }

            /** @var User|null $user */
            $user = User::query()->whereKey($invitation->user_id)->lockForUpdate()->first();

            if ($user === null || $user->status !== User::STATUS_PENDING_ACTIVATION) {
                throw ValidationException::withMessages(['ticket' => self::TICKET_FAILURE]);
            }

            $user->forceFill([
                // Le cast `hashed` du modèle hache la valeur.
                'password' => $password,
                'password_set_at' => now(),
                'status' => User::STATUS_ACTIVE,
                'phone_verified_at' => $user->phone_verified_at ?? now(),
            ])->save();

            $invitation->refresh();
            $this->events->record($invitation, 'password_set', ip: $ip);

            /** @var Company|null $company */
            $company = $invitation->company;

            // Seul rattachement possible : l'atelier qui a émis l'invitation.
            if ($company !== null && ! $user->companies()->whereKey($company->getKey())->exists()) {
                $user->companies()->attach($company->getKey(), [
                    'role' => Company::ROLE_TAILOR,
                    'joined_at' => now(),
                ]);
            }

            /** @var Employee|null $employee */
            $employee = $invitation->employee;

            if ($employee !== null && $employee->user_id === null) {
                $employee->forceFill(['user_id' => $user->id])->save();
            }

            EmployeeInvitation::query()
                ->where('employee_id', $invitation->employee_id)
                ->whereKeyNot($invitation->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'ticket_hash' => null]);

            $this->events->record($invitation, 'activated', ip: $ip);

            return $user->refresh();
        });
    }

    /** @return never */
    private function fail(string $limiterKey): void
    {
        RateLimiter::hit($limiterKey, 15 * 60);

        throw ValidationException::withMessages(['code' => self::GENERIC_FAILURE]);
    }
}
