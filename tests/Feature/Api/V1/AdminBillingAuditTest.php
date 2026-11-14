<?php

declare(strict_types=1);

use App\Models\AdminAction;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Console — facturation et audit', function (): void {
    it('met à jour un abonnement et trace la décision', function (): void {
        $admin = superAdmin();
        $company = Company::factory()->create(['name' => 'Atelier Facture']);
        $subscription = Subscription::query()->create([
            'company_id' => $company->id,
            'plan' => 'free',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/subscriptions/{$subscription->id}", [
                'plan' => 'pro',
                'status' => Subscription::STATUS_ACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.plan', 'pro');

        $this->assertDatabaseHas('admin_actions', ['action' => 'subscription.updated']);
    });

    it('crée une facture puis la marque payée', function (): void {
        $admin = superAdmin();
        $company = Company::factory()->create();
        $subscription = Subscription::query()->create([
            'company_id' => $company->id,
            'plan' => 'starter',
            'status' => Subscription::STATUS_PAST_DUE,
        ]);

        $invoice = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/subscriptions/{$subscription->id}/invoices", [
                'invoice_no' => 'INV-2026-001',
                'amount' => 15000,
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/subscriptions/{$subscription->id}/invoices/{$invoice['id']}", [
                'status' => 'paid',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        expect($subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE);
    });

    it('trace la suspension d’un compte dans le journal', function (): void {
        $admin = superAdmin();
        $member = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$member->id}", ['status' => User::STATUS_SUSPENDED])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/audit/actions')
            ->assertOk()
            ->assertJsonPath('data.items.0.action', 'user.status_updated');

        expect(AdminAction::query()->count())->toBe(1);
    });

    it('renvoie les membres et la restriction dans la fiche atelier', function (): void {
        $admin = superAdmin();
        $company = Company::factory()->create(['name' => 'Atelier Fiche']);
        $member = User::factory()->create();
        $company->members()->attach($member->id, ['role' => Company::ROLE_OWNER, 'joined_at' => now()]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.members.0.email', $member->email)
            ->assertJsonPath('data.employee_restricted_orders', false);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/companies/{$company->id}/restriction", [
                'employee_restricted_orders' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.employee_restricted_orders', true);
    });
});
