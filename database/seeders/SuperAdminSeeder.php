<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Compte de développement pour la console d'administration.
 *
 * Le mot de passe est volontairement trivial pour le travail local. Il ne
 * passerait pas les règles de `admin:create`, qui restent la voie normale pour
 * un vrai compte — d'où ce seeder séparé, refusé en production.
 */
final class SuperAdminSeeder extends Seeder
{
    public const EMAIL = 'admin@coudi.app';

    public const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Refusé : ce compte de démonstration ne doit pas exister en production.');
            $this->command->line('Utilisez « php artisan admin:create » pour un compte réel.');

            return;
        }

        $user = User::query()->firstOrNew(['email' => self::EMAIL]);

        $user->forceFill([
            'name' => 'Super Admin',
            'password' => Hash::make(self::PASSWORD),
            'role' => User::ROLE_SUPER_ADMIN,
            'email_verified_at' => now(),
            // Un mot de passe aussi faible ne doit pas être doublé d'un second
            // facteur laissé par une exécution précédente : on repart à zéro.
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->command->info(sprintf('Super admin : %s / %s', self::EMAIL, self::PASSWORD));
    }
}
