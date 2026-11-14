<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Console — utilisateurs', function (): void {
    it('liste les comptes avec leurs états et les compte', function (): void {
        $admin = superAdmin();

        User::factory()->create(['name' => 'Fatou', 'email' => 'fatou@example.com']);
        $pending = User::factory()->create([
            'name' => 'Awa', 'email' => 'awa@example.com', 'status' => User::STATUS_PENDING_ACTIVATION,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/users?search=awa')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $pending->id)
            ->assertJsonPath('data.counts.total', 3);
    });

    it('suspend puis réactive un compte membre', function (): void {
        $member = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin = superAdmin();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$member->id}", ['status' => User::STATUS_SUSPENDED])
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_SUSPENDED);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$member->id}", ['status' => User::STATUS_ACTIVE])
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_ACTIVE);
    });

    it('refuse de suspendre un compte administrateur', function (): void {
        $admin = superAdmin();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$admin->id}", ['status' => User::STATUS_SUSPENDED])
            ->assertForbidden();
    });

    it('renvoie les ateliers et fiches employé du compte', function (): void {
        $admin = superAdmin();
        $member = User::factory()->create();

        $company = new Company;
        $company->fill(['name' => 'Atelier Fatou']);
        $company->save();

        $company->members()->attach($member->id, ['role' => Company::ROLE_TAILOR, 'joined_at' => now()]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/users/{$member->id}")
            ->assertOk()
            ->assertJsonPath('data.memberships.0.role', Company::ROLE_TAILOR)
            ->assertJsonPath('data.memberships.0.name', 'Atelier Fatou');
    });
});
