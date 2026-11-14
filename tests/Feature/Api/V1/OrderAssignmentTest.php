<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use App\Services\Order\OrderAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{owner: User, company: Company, employeeA: Employee, employeeB: Employee}
 */
function assignmentFixture(): array
{
    $owner = User::factory()->create();

    $company = new Company;
    $company->fill(['name' => 'Atelier Fatou', 'status' => Company::STATUS_ACTIVE]);
    $company->save();

    $company->members()->attach($owner->id, [
        'role' => Company::ROLE_OWNER,
        'joined_at' => now(),
    ]);

    $employeeA = Employee::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Awa',
        'status' => 'active',
    ]);

    $employeeB = Employee::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Moussa',
        'status' => 'active',
    ]);

    Sanctum::actingAs($owner);

    return ['owner' => $owner, 'company' => $company, 'employeeA' => $employeeA, 'employeeB' => $employeeB];
}

function assignmentOrder(Company $company): Order
{
    $client = Client::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Cliente',
    ]);

    return Order::query()->create([
        'company_id' => $company->getKey(),
        'client_id' => $client->id,
        'order_code' => 'CMD-'.Str::upper(Str::random(5)),
        'status' => Order::STATUS_NEW,
    ]);
}

describe('Affectations', function (): void {
    it('affecte plusieurs employés puis conserve l’historique des retraits', function (): void {
        ['company' => $company, 'employeeA' => $a, 'employeeB' => $b] = assignmentFixture();
        $order = assignmentOrder($company);

        $this->postJson(
            "/api/v1/orders/{$order->id}/assignments",
            ['employee_ids' => [$a->id, $b->id]],
            ['X-Company-Id' => $company->getKey()],
        )->assertOk()->assertJsonCount(2, 'data');

        $order->refresh();

        expect($order->activeAssigneeIds())->toHaveCount(2)
            ->and($order->assigned_employee_id)->not->toBeNull();

        $this->postJson(
            "/api/v1/orders/{$order->id}/assignments",
            ['employee_ids' => [$b->id]],
            ['X-Company-Id' => $company->getKey()],
        )->assertOk();

        $order->refresh();

        expect($order->activeAssigneeIds())->toBe([$b->id])
            ->and($order->assigned_employee_id)->toBe($b->id);

        $this->assertDatabaseHas('order_assignments', [
            'order_id' => $order->id,
            'employee_id' => $a->id,
            'status' => OrderAssignment::STATUS_REMOVED,
        ]);

        // La ligne retirée n'est pas supprimée : l'historique reste lisible.
        expect(OrderAssignment::query()->where('order_id', $order->id)->count())->toBe(2);
    });

    it('refuse un employé d’un autre atelier', function (): void {
        ['company' => $company] = assignmentFixture();
        $order = assignmentOrder($company);

        $autre = new Company;
        $autre->fill(['name' => 'Atelier voisin']);
        $autre->save();

        $intrus = Employee::query()->create([
            'company_id' => $autre->getKey(),
            'name' => 'Inconnu',
        ]);

        $this->postJson(
            "/api/v1/orders/{$order->id}/assignments",
            ['employee_ids' => [$intrus->id]],
            ['X-Company-Id' => $company->getKey()],
        )->assertStatus(422);

        expect(OrderAssignment::query()->where('order_id', $order->id)->count())->toBe(0);
    });

    it('interdit l’affectation à un rôle lecteur', function (): void {
        ['owner' => $owner, 'company' => $company, 'employeeA' => $a] = assignmentFixture();
        $order = assignmentOrder($company);

        $company->members()->updateExistingPivot($owner->id, ['role' => Company::ROLE_TAILOR]);

        $this->postJson(
            "/api/v1/orders/{$order->id}/assignments",
            ['employee_ids' => [$a->id]],
            ['X-Company-Id' => $company->getKey()],
        )->assertForbidden();
    });
});

describe('Synchronisation restreinte', function (): void {
    it('ne livre à l’employé que ses commandes et leurs clients', function (): void {
        ['owner' => $owner, 'company' => $company, 'employeeA' => $a, 'employeeB' => $b] = assignmentFixture();

        CompanySetting::query()->create([
            'company_id' => $company->getKey(),
            'employee_restricted_orders' => true,
        ]);

        $employeeUser = User::query()->create([
            'name' => 'Awa',
            'email' => 'awa-'.$company->getKey().'@example.com',
            'password' => 'motdepasse123',
            'status' => User::STATUS_ACTIVE,
        ]);

        $company->members()->attach($employeeUser->id, [
            'role' => Company::ROLE_TAILOR,
            'joined_at' => now(),
        ]);

        $a->forceFill(['user_id' => $employeeUser->id])->save();

        $assigned = assignmentOrder($company);
        $notAssigned = assignmentOrder($company);

        app(OrderAssignmentService::class)->assign($assigned, [$a->id], $owner);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $employeeUser->id,
            'platform' => 'android',
        ]);

        Sanctum::actingAs($employeeUser);

        $response = $this->postJson('/api/v1/sync/pull', ['since' => 0], [
            'X-Company-Id' => $company->getKey(),
            'X-Device-Id' => $device->getKey(),
        ])->assertOk();

        $orderIds = collect($response->json('data.changes.orders') ?? [])->pluck('id')->all();
        $clientIds = collect($response->json('data.changes.clients') ?? [])->pluck('id')->all();

        expect($orderIds)->toContain($assigned->id)
            ->and($orderIds)->not->toContain($notAssigned->id)
            ->and($clientIds)->toContain($assigned->client_id)
            ->and($clientIds)->not->toContain($notAssigned->client_id);

        // L'employé reste lecteur : aucun push autorisé.
        $this->postJson('/api/v1/sync/push', ['ops' => [[
            'op_id' => (string) Str::uuid(),
            'entity' => 'clients',
            'op' => 'upsert',
            'id' => (string) Str::uuid(),
            'updated_at' => now()->toIso8601String(),
            'data' => ['name' => 'Interdit'],
        ]]], [
            'X-Company-Id' => $company->getKey(),
            'X-Device-Id' => $device->getKey(),
        ])->assertForbidden();
    });

    it('signale un changement de périmètre et repart de zéro', function (): void {
        ['owner' => $owner, 'company' => $company, 'employeeA' => $a] = assignmentFixture();

        $employeeUser = User::query()->create([
            'name' => 'Awa',
            'email' => 'awa-scope-'.$company->getKey().'@example.com',
            'password' => 'motdepasse123',
            'status' => User::STATUS_ACTIVE,
        ]);

        $company->members()->attach($employeeUser->id, [
            'role' => Company::ROLE_TAILOR,
            'joined_at' => now(),
        ]);

        $a->forceFill(['user_id' => $employeeUser->id])->save();

        $assigned = assignmentOrder($company);
        $notAssigned = assignmentOrder($company);

        app(OrderAssignmentService::class)->assign($assigned, [$a->id], $owner);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $employeeUser->id,
            'platform' => 'android',
        ]);

        Sanctum::actingAs($employeeUser);

        // Premier pull sans restriction : tout est visible.
        $this->postJson('/api/v1/sync/pull', ['since' => 0], [
            'X-Company-Id' => $company->getKey(),
            'X-Device-Id' => $device->getKey(),
        ])->assertOk()->assertJsonPath('data.restricted', false);

        // Le patron active la restriction : l'appareil doit repartir de zéro.
        CompanySetting::query()->updateOrCreate(
            ['company_id' => $company->getKey()],
            ['employee_restricted_orders' => true],
        );

        $restricted = $this->postJson('/api/v1/sync/pull', ['since' => 999999], [
            'X-Company-Id' => $company->getKey(),
            'X-Device-Id' => $device->getKey(),
        ])->assertOk()
            ->assertJsonPath('data.reset_required', true)
            ->assertJsonPath('data.restricted', true);

        $orderIds = collect($restricted->json('data.changes.orders') ?? [])->pluck('id')->all();

        expect($orderIds)->toContain($assigned->id)
            ->and($orderIds)->not->toContain($notAssigned->id);
    });

    it('force une reprise à zéro quand les affectations changent', function (): void {
        ['owner' => $owner, 'company' => $company, 'employeeA' => $a] = assignmentFixture();

        CompanySetting::query()->create([
            'company_id' => $company->getKey(),
            'employee_restricted_orders' => true,
        ]);

        $employeeUser = User::query()->create([
            'name' => 'Awa',
            'email' => 'awa-assign-'.$company->getKey().'@example.com',
            'password' => 'motdepasse123',
            'status' => User::STATUS_ACTIVE,
        ]);

        $company->members()->attach($employeeUser->id, [
            'role' => Company::ROLE_TAILOR,
            'joined_at' => now(),
        ]);

        $a->forceFill(['user_id' => $employeeUser->id])->save();

        $assigned = assignmentOrder($company);
        $second = assignmentOrder($company);

        app(OrderAssignmentService::class)->assign($assigned, [$a->id], $owner);

        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $employeeUser->id,
            'platform' => 'android',
        ]);

        Sanctum::actingAs($employeeUser);

        $headers = [
            'X-Company-Id' => $company->getKey(),
            'X-Device-Id' => $device->getKey(),
        ];

        $this->postJson('/api/v1/sync/pull', ['since' => 0], $headers)
            ->assertOk()
            ->assertJsonPath('data.reset_required', false);

        // Une nouvelle affectation change le périmètre : l'appareil doit
        // repartir de zéro pour recevoir la commande nouvellement visible.
        app(OrderAssignmentService::class)->assign($second, [$a->id], $owner);

        $this->postJson('/api/v1/sync/pull', ['since' => 999999], $headers)
            ->assertOk()
            ->assertJsonPath('data.reset_required', true);

        $orderIds = collect(
            $this->postJson('/api/v1/sync/pull', ['since' => 0], $headers)->json('data.changes.orders') ?? []
        )->pluck('id')->all();

        expect($orderIds)->toContain($assigned->id, $second->id);
    });
});
