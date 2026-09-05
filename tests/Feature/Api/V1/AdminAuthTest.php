<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LoginAudit;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

function superAdmin(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->forceFill(['role' => User::ROLE_SUPER_ADMIN])->save();

    return $user;
}

beforeEach(function (): void {
    RateLimiter::clear('admin-login:admin@coudi.app|127.0.0.1');
});

describe('Admin login', function (): void {
    it('signs in a super admin without two-factor', function (): void {
        superAdmin(['email' => 'admin@coudi.app', 'password' => 'correct-horse-1!']);

        $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@coudi.app',
            'password' => 'correct-horse-1!',
        ])
            ->assertOk()
            ->assertJsonPath('data.two_factor_required', false)
            ->assertJsonPath('data.user.role', 'super_admin')
            ->assertJsonStructure(['data' => ['access_token', 'expires_at']]);

        $this->assertDatabaseHas('login_audits', [
            'email' => 'admin@coudi.app',
            'status' => LoginAudit::SUCCESS,
        ]);
    });

    it('refuses a valid account that is not a super admin', function (): void {
        User::factory()->create(['email' => 'tailor@coudi.app', 'password' => 'correct-horse-1!']);

        $this->postJson('/api/v1/admin/login', [
            'email' => 'tailor@coudi.app',
            'password' => 'correct-horse-1!',
        ])->assertForbidden();

        $this->assertDatabaseHas('login_audits', [
            'email' => 'tailor@coudi.app',
            'status' => LoginAudit::FORBIDDEN,
        ]);
    });

    it('gives the same answer for a wrong password and an unknown account', function (): void {
        superAdmin(['email' => 'admin@coudi.app', 'password' => 'correct-horse-1!']);

        $wrongPassword = $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@coudi.app', 'password' => 'nope',
        ]);
        $unknown = $this->postJson('/api/v1/admin/login', [
            'email' => 'ghost@coudi.app', 'password' => 'nope',
        ]);

        expect($wrongPassword->status())->toBe($unknown->status())
            ->and($wrongPassword->json('message'))->toBe($unknown->json('message'));
    });

    it('locks the account after repeated failures', function (): void {
        superAdmin(['email' => 'admin@coudi.app', 'password' => 'correct-horse-1!']);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/admin/login', [
                'email' => 'admin@coudi.app', 'password' => 'nope',
            ]);
        }

        // Même le bon mot de passe est refusé pendant le verrouillage.
        $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@coudi.app', 'password' => 'correct-horse-1!',
        ])->assertStatus(429);

        $this->assertDatabaseHas('login_audits', [
            'email' => 'admin@coudi.app', 'status' => LoginAudit::LOCKED,
        ]);
    });
});

describe('Admin two-factor', function (): void {
    it('withholds the session until the code is verified', function (): void {
        $service = app(TwoFactorService::class);
        $secret = $service->generateSecret();
        $user = superAdmin(['email' => 'admin@coudi.app', 'password' => 'correct-horse-1!']);
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['aaaaa-bbbbb'],
        ])->save();

        $login = $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@coudi.app', 'password' => 'correct-horse-1!',
        ])->assertOk()->assertJsonPath('data.two_factor_required', true);

        expect($login->json('data.access_token'))->toBeNull();

        $challenge = $login->json('data.challenge');

        $this->postJson('/api/v1/admin/login/two-factor', [
            'challenge' => $challenge, 'code' => '000000',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/admin/login/two-factor', [
            'challenge' => $challenge,
            'code' => (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp($secret),
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token']]);
    });

    it('accepts a recovery code once and then burns it', function (): void {
        $user = superAdmin(['email' => 'admin@coudi.app', 'password' => 'correct-horse-1!']);
        $user->forceFill([
            'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['aaaaa-bbbbb', 'ccccc-ddddd'],
        ])->save();

        $challenge = $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@coudi.app', 'password' => 'correct-horse-1!',
        ])->json('data.challenge');

        $this->postJson('/api/v1/admin/login/two-factor', [
            'challenge' => $challenge, 'recovery_code' => 'aaaaa-bbbbb',
        ])->assertOk();

        expect($user->fresh()->two_factor_recovery_codes)->toBe(['ccccc-ddddd']);
    });

    it('rejects a challenge token that was already spent', function (): void {
        $service = app(TwoFactorService::class);
        $secret = $service->generateSecret();
        $user = superAdmin(['email' => 'admin@coudi.app', 'password' => 'correct-horse-1!']);
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $challenge = $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@coudi.app', 'password' => 'correct-horse-1!',
        ])->json('data.challenge');

        $code = (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp($secret);
        $this->postJson('/api/v1/admin/login/two-factor', ['challenge' => $challenge, 'code' => $code])->assertOk();
        $this->postJson('/api/v1/admin/login/two-factor', ['challenge' => $challenge, 'code' => $code])->assertUnauthorized();
    });
});

describe('Admin guard', function (): void {
    it('closes every admin route to a non-admin token', function (): void {
        $user = User::factory()->create();

        foreach ([
            '/api/v1/admin/me',
            '/api/v1/admin/companies',
            '/api/v1/admin/diagnostics/bundles',
            '/api/v1/admin/diagnostics/commands',
        ] as $route) {
            $this->actingAs($user, 'sanctum')->getJson($route)->assertForbidden();
        }
    });

    it('closes every admin route to an anonymous caller', function (): void {
        $this->getJson('/api/v1/admin/companies')->assertUnauthorized();
    });

    it('lets a super admin list and configure workshops', function (): void {
        $company = Company::factory()->create(['name' => 'Atelier Nafissa']);
        $admin = superAdmin();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/companies')
            ->assertOk()
            ->assertJsonPath('data.items.0.name', 'Atelier Nafissa')
            ->assertJsonPath('data.items.0.diagnostic_settings_overridden', false);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/companies/{$company->id}/diagnostic-settings", [
                'log_rotation' => 'daily',
                'log_retention' => 3,
                'log_level' => 'debug',
                'log_api_calls' => true,
                'log_api_bodies' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.log_level', 'debug');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.diagnostic_settings_overridden', true);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/companies/{$company->id}/diagnostic-settings")
            ->assertOk()
            ->assertJsonPath('data.log_level', 'info');
    });
});
