<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\DiagnosticSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Diagnostic settings', function (): void {
    it('serves the global default to an authenticated device', function (): void {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/diagnostics/settings')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['log_rotation' => 'monthly', 'log_retention' => 6],
            ]);
    });

    it('requires authentication', function (): void {
        $this->getJson('/api/v1/diagnostics/settings')->assertUnauthorized();
    });

    it('updates the global default', function (): void {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/diagnostics/settings', [
                'log_rotation' => 'weekly',
                'log_retention' => 12,
            ])
            ->assertOk()
            ->assertJson(['data' => ['log_rotation' => 'weekly', 'log_retention' => 12]]);

        // Pas de doublon : la ligne globale est mise à jour, pas recréée.
        expect(DiagnosticSetting::whereNull('company_id')->count())->toBe(1);
    });

    it('rejects an unknown rotation period', function (): void {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/diagnostics/settings', [
                'log_rotation' => 'hourly',
                'log_retention' => 6,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('log_rotation');
    });

    it('rejects a retention outside bounds', function (): void {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/diagnostics/settings', [
                'log_rotation' => 'daily',
                'log_retention' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('log_retention');
    });

    it('prefers a company row over the global default', function (): void {
        $company = Company::factory()->create();
        DiagnosticSetting::create([
            'company_id' => $company->id,
            'log_rotation' => 'daily',
            'log_retention' => 3,
        ]);

        $setting = DiagnosticSetting::resolveFor($company->id);

        expect($setting->log_rotation)->toBe('daily')
            ->and($setting->log_retention)->toBe(3);
    });

    it('falls back to the global default for a company without a row', function (): void {
        $company = Company::factory()->create();

        expect(DiagnosticSetting::resolveFor($company->id)->log_rotation)->toBe('monthly');
    });
});
