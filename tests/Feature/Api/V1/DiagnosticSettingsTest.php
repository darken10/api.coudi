<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\DiagnosticCommand;
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
                'data' => [
                    'log_rotation' => 'monthly',
                    'log_retention' => 6,
                    'log_level' => 'info',
                    'log_api_calls' => true,
                    'log_api_bodies' => false,
                    'command' => null,
                ],
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
                'log_level' => 'debug',
                'log_api_calls' => true,
                'log_api_bodies' => true,
            ])
            ->assertOk()
            ->assertJson(['data' => [
                'log_rotation' => 'weekly',
                'log_retention' => 12,
                'log_level' => 'debug',
                'log_api_bodies' => true,
            ]]);

        // Pas de doublon : la ligne globale est mise à jour, pas recréée.
        expect(DiagnosticSetting::whereNull('company_id')->count())->toBe(1);
    });

    it('rejects an unknown rotation period', function (): void {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/diagnostics/settings', [
                'log_rotation' => 'hourly',
                'log_retention' => 6,
                'log_level' => 'info',
                'log_api_calls' => true,
                'log_api_bodies' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('log_rotation');
    });

    it('rejects a retention outside bounds', function (): void {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/diagnostics/settings', [
                'log_rotation' => 'daily',
                'log_retention' => 0,
                'log_level' => 'info',
                'log_api_calls' => true,
                'log_api_bodies' => false,
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

    it('rejects an unknown log level', function (): void {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/diagnostics/settings', [
                'log_rotation' => 'daily',
                'log_retention' => 6,
                'log_level' => 'verbose',
                'log_api_calls' => true,
                'log_api_bodies' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('log_level');
    });
});

describe('Diagnostic commands', function (): void {
    it('creates a new-log-file command', function (): void {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/diagnostics/commands', [
                'action' => 'new_log_file',
                'file_name' => 'incident-4712',
            ])
            ->assertStatus(201)
            ->assertJson(['data' => ['action' => 'new_log_file', 'file_name' => 'incident-4712']]);
    });

    it('rejects a file name that could escape the logs folder', function (): void {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/diagnostics/commands', [
                'action' => 'new_log_file',
                'file_name' => '../../etc/passwd',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file_name');
    });

    it('hands a pending command to the device through the settings endpoint', function (): void {
        $command = DiagnosticCommand::create([
            'action' => 'new_log_file',
            'file_name' => 'incident-9',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/diagnostics/settings')
            ->assertOk()
            ->assertJson(['data' => ['command' => [
                'id' => $command->id,
                'action' => 'new_log_file',
                'file_name' => 'incident-9',
            ]]]);
    });

    it('stops handing out a command once the device acknowledges it', function (): void {
        $user = User::factory()->create();
        $command = DiagnosticCommand::create(['action' => 'new_log_file', 'file_name' => 'once']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/diagnostics/commands/{$command->id}/ack", ['status' => 'done'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/diagnostics/settings')
            ->assertOk()
            ->assertJson(['data' => ['command' => null]]);
    });

    it('keeps handing the command to a device that has not acknowledged it', function (): void {
        $command = DiagnosticCommand::create(['action' => 'new_log_file', 'file_name' => 'shared']);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/diagnostics/commands/{$command->id}/ack", ['status' => 'done'])
            ->assertOk();

        // Un autre appareil ne l'a pas encore vu : il doit toujours le recevoir.
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/diagnostics/settings')
            ->assertOk()
            ->assertJson(['data' => ['command' => ['file_name' => 'shared']]]);
    });

    it('records a failed execution reported by the device', function (): void {
        $user = User::factory()->create();
        $command = DiagnosticCommand::create(['action' => 'new_log_file', 'file_name' => 'broken']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/diagnostics/commands/{$command->id}/ack", [
                'status' => 'failed',
                'message' => 'Espace disque insuffisant',
            ])
            ->assertOk();

        $this->assertDatabaseHas('diagnostic_command_acks', [
            'command_id' => $command->id,
            'user_id' => $user->id,
            'status' => 'failed',
            'message' => 'Espace disque insuffisant',
        ]);
    });

    it('creates a resume-rotation command without a file name', function (): void {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/diagnostics/commands', ['action' => 'resume_rotation'])
            ->assertStatus(201)
            ->assertJson(['data' => ['action' => 'resume_rotation', 'file_name' => null]]);
    });

    it('requires a file name for a new-log-file command', function (): void {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/diagnostics/commands', ['action' => 'new_log_file'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file_name');
    });
});
