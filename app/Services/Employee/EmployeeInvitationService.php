<?php

declare(strict_types=1);

namespace App\Services\Employee;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invitation d'un employé à activer son compte.
 *
 * Aucun mot de passe n'est choisi par le patron : l'employé le définit
 * lui-même en ouvrant le lien. Le compte naît `pending_activation` et ne peut
 * donc pas se connecter tant que l'invitation n'a pas été consommée.
 */
final class EmployeeInvitationService
{
    /** @return array{invitation: EmployeeInvitation, token: string, url: string, deep_link: string} */
    public function invite(
        Company $company,
        Employee $employee,
        User $actor,
        ?string $email = null,
        ?string $phone = null,
    ): array {
        $email = $this->normalizeEmail($email ?? $employee->email);
        $phone = $this->normalizePhone($phone ?? $employee->phone ?? $employee->whatsapp);

        if ($email === null && $phone === null) {
            throw ValidationException::withMessages([
                'email' => 'Renseignez au moins un email ou un téléphone pour inviter cet employé.',
            ]);
        }

        $user = $this->resolveUser($company, $employee, $email, $phone);

        if ($user->status === User::STATUS_ACTIVE && $employee->user_id !== null) {
            throw ValidationException::withMessages([
                'employee' => 'Ce compte employé est déjà activé.',
            ]);
        }

        if ($employee->user_id === null) {
            $employee->forceFill(['user_id' => $user->id])->save();
        }

        // Une seule invitation vivante à la fois : la précédente est révoquée.
        EmployeeInvitation::query()
            ->where('employee_id', $employee->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $ttl = (int) config('koudi.invitation_ttl_hours', 72);

        [$plain, $invitation] = EmployeeInvitation::issue([
            'company_id' => $company->getKey(),
            'employee_id' => $employee->getKey(),
            'user_id' => $user->id,
            'email' => $email,
            'phone' => $phone,
            'created_by' => $actor->id,
        ], $ttl);

        $url = sprintf('%s?token=%s', config('koudi.activation_url'), urlencode($plain));
        $deepLink = sprintf(
            '%s://activer?token=%s',
            config('koudi.mobile_scheme'),
            urlencode($plain),
        );

        if ($email !== null) {
            $this->sendInvitationEmail($email, $employee->name, $company->name ?? 'votre atelier', $url);
        }

        return [
            'invitation' => $invitation,
            'token' => $plain,
            'url' => $url,
            'deep_link' => $deepLink,
        ];
    }

    /** Aperçu public, avant toute saisie de mot de passe. */
    public function preview(string $plain): EmployeeInvitation
    {
        $invitation = EmployeeInvitation::findByPlainToken($plain);

        if ($invitation === null || ! $invitation->isUsable()) {
            throw ValidationException::withMessages([
                'token' => 'Cette invitation est invalide, expirée ou déjà utilisée.',
            ]);
        }

        return $invitation;
    }

    /**
     * Active le compte et retourne l'utilisateur prêt à recevoir ses jetons.
     *
     * @param  array{name: string, password: string, email?: string|null, phone?: string|null}  $data
     */
    public function accept(string $plain, array $data): User
    {
        $invitation = $this->preview($plain);

        /** @var User|null $user */
        $user = $invitation->user;

        if ($user === null) {
            throw ValidationException::withMessages([
                'token' => 'Le compte associé à cette invitation n’existe plus.',
            ]);
        }

        if ($user->status === User::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'token' => 'Ce compte est déjà activé. Connectez-vous.',
            ]);
        }

        $email = $this->normalizeEmail($data['email'] ?? $invitation->email ?? $user->email);
        $phone = $this->normalizePhone($data['phone'] ?? $invitation->phone ?? $user->phone);

        if ($email === null && $phone === null) {
            throw ValidationException::withMessages([
                'email' => 'Un email ou un téléphone est nécessaire pour vous connecter.',
            ]);
        }

        $this->assertAvailable($user, $email, $phone);

        DB::transaction(function () use ($user, $invitation, $data, $email, $phone): void {
            $user->forceFill([
                'name' => trim($data['name']),
                // Le cast `hashed` hache automatiquement la valeur.
                'password' => $data['password'],
                'email' => $email,
                'phone' => $phone,
                'whatsapp' => $phone,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => $email === null ? null : now(),
            ])->save();

            $company = $invitation->company;
            $employee = $invitation->employee;

            if ($company !== null && ! $user->companies()->whereKey($company->getKey())->exists()) {
                $user->companies()->attach($company->getKey(), [
                    'role' => Company::ROLE_TAILOR,
                    'joined_at' => now(),
                ]);
            }

            if ($employee !== null && $employee->user_id === null) {
                $employee->forceFill(['user_id' => $user->id])->save();
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            EmployeeInvitation::query()
                ->where('employee_id', $invitation->employee_id)
                ->whereKeyNot($invitation->getKey())
                ->whereNull('accepted_at')
                ->update(['revoked_at' => now()]);
        });

        return $user->refresh();
    }

    public function revoke(EmployeeInvitation $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => 'Une invitation déjà utilisée ne peut pas être révoquée.',
            ]);
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
    }

    private function resolveUser(
        Company $company,
        Employee $employee,
        ?string $email,
        ?string $phone,
    ): User {
        if ($employee->user_id !== null) {
            /** @var User|null $user */
            $user = User::query()->find($employee->user_id);

            if ($user === null) {
                throw ValidationException::withMessages([
                    'employee' => 'Le compte lié à cet employé est introuvable.',
                ]);
            }

            return $user;
        }

        $user = User::query()
            ->when($email !== null, fn ($query) => $query->where('email', $email))
            ->when($email === null && $phone !== null, fn ($query) => $query->where('phone', $phone))
            ->first();

        if ($user !== null) {
            $alreadyLinked = Employee::query()
                ->where('company_id', $company->getKey())
                ->where('user_id', $user->id)
                ->whereKeyNot($employee->getKey())
                ->exists();

            if ($alreadyLinked) {
                throw ValidationException::withMessages([
                    'employee' => 'Ce compte est déjà rattaché à un autre employé de l’atelier.',
                ]);
            }

            return $user;
        }

        return User::query()->create([
            'name' => $employee->name,
            'email' => $email,
            'phone' => $phone,
            'whatsapp' => $phone,
            // Jamais utilisé : l'activation remplace immédiatement ce secret.
            'password' => Str::random(48),
            'status' => User::STATUS_PENDING_ACTIVATION,
        ]);
    }

    private function assertAvailable(User $user, ?string $email, ?string $phone): void
    {
        if ($email !== null) {
            $taken = User::query()->where('email', $email)->whereKeyNot($user->id)->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'email' => 'Cet email est déjà utilisé par un autre compte.',
                ]);
            }
        }

        if ($phone !== null) {
            $taken = User::query()->where('phone', $phone)->whereKeyNot($user->id)->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'phone' => 'Ce téléphone est déjà utilisé par un autre compte.',
                ]);
            }
        }
    }

    private function sendInvitationEmail(string $email, string $name, string $company, string $url): void
    {
        try {
            Mail::raw(
                sprintf(
                    "Bonjour %s,\n\n%s vous invite à rejoindre l'atelier sur Koudi.\n\nOuvrez ce lien pour activer votre compte :\n%s\n\nCe lien expire dans %d heures et ne peut servir qu'une fois.",
                    $name,
                    $company,
                    $url,
                    (int) config('koudi.invitation_ttl_hours', 72),
                ),
                static function ($message) use ($email): void {
                    $message->to($email)->subject('Votre invitation Koudi');
                },
            );
        } catch (\Throwable $exception) {
            // L'admin garde le lien affiché : un échec SMTP ne doit pas annuler
            // l'invitation, mais il doit rester visible dans les journaux.
            Log::warning('Envoi de l’invitation impossible', [
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = $email === null ? null : trim($email);

        return $email === null || $email === '' ? null : mb_strtolower($email);
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = (string) preg_replace('/[^0-9+]/', '', $phone);

        return $phone === '' ? null : $phone;
    }
}
