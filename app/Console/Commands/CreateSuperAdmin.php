<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Crée le premier compte d'administration — aucune inscription n'est exposée. */
final class CreateSuperAdmin extends Command
{
    protected $signature = 'admin:create
                            {--name= : Nom affiché}
                            {--email= : Adresse e-mail}
                            {--password= : Mot de passe (demandé si absent)}';

    protected $description = "Crée ou promeut un compte super admin pour la console d'administration";

    public function handle(): int
    {
        $name = $this->text('name', fn (): mixed => $this->ask('Nom'));
        $email = mb_strtolower($this->text('email', fn (): mixed => $this->ask('E-mail')));
        $password = $this->text('password', fn (): mixed => $this->secret('Mot de passe'));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', Password::min(12)->letters()->numbers()->symbols()],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing) {
            $existing->forceFill([
                'role' => User::ROLE_SUPER_ADMIN,
                'password' => Hash::make($password),
            ])->save();

            $this->info("Compte {$email} promu super admin et mot de passe réinitialisé.");

            return self::SUCCESS;
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ])->forceFill([
            'role' => User::ROLE_SUPER_ADMIN,
            'email_verified_at' => now(),
        ])->save();

        $this->info("Super admin {$email} créé.");
        $this->line('Activez le double facteur dès la première connexion.');

        return self::SUCCESS;
    }

    /** Lit une option, ou la demande — en garantissant une chaîne. */
    private function text(string $option, callable $prompt): string
    {
        $value = $this->option($option);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $answer = $prompt();

        return is_string($answer) ? $answer : '';
    }
}
