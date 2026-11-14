<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{owner: User, company: Company, employee: Employee}
 */
function employeeWorkshopFixture(string $employeeEmail = 'tailleur@example.com'): array
{
    $owner = User::factory()->create();

    $company = new Company;
    $company->fill(['name' => 'Atelier Fatou', 'status' => Company::STATUS_ACTIVE]);
    $company->save();

    $company->members()->attach($owner->id, [
        'role' => Company::ROLE_OWNER,
        'joined_at' => now(),
    ]);

    $employee = Employee::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Awa Traoré',
        'role' => 'tailleur',
        'email' => $employeeEmail,
        'phone' => '+22370000001',
        'status' => 'active',
    ]);

    Sanctum::actingAs($owner);

    return ['owner' => $owner, 'company' => $company, 'employee' => $employee];
}

describe('Invitation employé', function (): void {
    it('crée une invitation, la prévisualise puis active le compte', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();

        $created = $this->postJson(
            "/api/v1/employees/{$employee->id}/invitations",
            ['email' => 'awa@example.com'],
            ['X-Company-Id' => $company->getKey()],
        )->assertCreated();

        $token = $created->json('data.token');

        expect($token)->toBeString()->not->toBeEmpty();

        $this->getJson('/api/v1/employee-invitations/'.$token)
            ->assertOk()
            ->assertJsonPath('data.employee_name', 'Awa Traoré')
            ->assertJsonPath('data.company_name', 'Atelier Fatou');

        $accepted = $this->postJson('/api/v1/employee-invitations/'.$token.'/accept', [
            'name' => 'Awa Traoré',
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'phone' => '+22370000001',
        ])->assertOk();

        expect($accepted->json('data.access_token'))->toBeString();

        $this->assertDatabaseHas('users', [
            'email' => 'awa@example.com',
            'status' => User::STATUS_ACTIVE,
        ]);

        $employee->refresh();

        expect($employee->user_id)->not->toBeNull()
            ->and($employee->user?->status)->toBe(User::STATUS_ACTIVE)
            ->and($employee->user?->roleIn($company))->toBe(Company::ROLE_TAILOR);

        $this->postJson('/api/v1/login', [
            'login' => '+22370000001',
            'password' => 'motdepasse123',
        ])->assertOk()->assertJsonPath('success', true);
    });

    it('refuse de réutiliser le même jeton', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();

        $token = $this->postJson(
            "/api/v1/employees/{$employee->id}/invitations",
            ['email' => 'awa@example.com'],
            ['X-Company-Id' => $company->getKey()],
        )->json('data.token');

        $payload = [
            'name' => 'Awa Traoré',
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
        ];

        $this->postJson('/api/v1/employee-invitations/'.$token.'/accept', $payload)->assertOk();
        $this->postJson('/api/v1/employee-invitations/'.$token.'/accept', $payload)->assertStatus(422);
    });

    it('refuse de connecter un compte en attente d’activation', function (): void {
        ['company' => $company, 'employee' => $employee] = employeeWorkshopFixture();

        $pending = User::query()->create([
            'name' => 'Awa',
            'email' => 'awa2@example.com',
            'password' => 'motdepasse123',
            'status' => User::STATUS_PENDING_ACTIVATION,
        ]);

        $employee->forceFill(['user_id' => $pending->id])->save();

        $this->postJson('/api/v1/login', [
            'email' => 'awa2@example.com',
            'password' => 'motdepasse123',
        ])->assertForbidden();
    });

    it('ne laisse pas un patron inviter l’employé d’un autre atelier', function (): void {
        ['company' => $company] = employeeWorkshopFixture();

        $autre = new Company;
        $autre->fill(['name' => 'Atelier voisin']);
        $autre->save();

        $intrus = Employee::query()->create([
            'company_id' => $autre->getKey(),
            'name' => 'Inconnu',
            'email' => 'inconnu@example.com',
        ]);

        $this->postJson(
            "/api/v1/employees/{$intrus->id}/invitations",
            ['email' => 'inconnu@example.com'],
            ['X-Company-Id' => $company->getKey()],
        )->assertNotFound();
    });

    it('interdit l’invitation à un rôle lecteur', function (): void {
        ['owner' => $owner, 'company' => $company, 'employee' => $employee] = employeeWorkshopFixture();

        $company->members()->updateExistingPivot($owner->id, ['role' => Company::ROLE_TAILOR]);

        $this->postJson(
            "/api/v1/employees/{$employee->id}/invitations",
            ['email' => 'awa@example.com'],
            ['X-Company-Id' => $company->getKey()],
        )->assertForbidden();
    });
});
