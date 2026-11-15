<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Restauration sur une installation neuve : aucune base locale, un appareil
 * jamais vu, le seul compte pour retrouver ses données.
 */

/** @return array{0: User, 1: Company} */
function restoreWorkshop(string $name = 'Atelier Alice', ?User $owner = null): array
{
    $owner ??= User::factory()->create();
    $company = new Company;
    $company->fill(['name' => $name, 'status' => Company::STATUS_ACTIVE]);
    $company->save();
    $company->members()->attach($owner->id, ['role' => Company::ROLE_OWNER, 'joined_at' => now()]);

    return [$owner, $company];
}

function freshDevice(User $user): Device
{
    return Device::query()->create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'platform' => 'android']);
}

/** @return array<string, mixed> */
function restoreOp(string $entity, array $data, ?string $id = null): array
{
    return [
        'op_id' => (string) Str::uuid(), 'entity' => $entity, 'op' => 'upsert',
        'id' => $id ?? (string) Str::uuid(), 'updated_at' => now()->toIso8601String(), 'data' => $data,
    ];
}

/**
 * Rejoue un rapatriement complet comme le téléphone, page par page, et vérifie
 * que chaque page est autonome : tout parent référencé y figure ou a déjà été reçu.
 *
 * @return array{received: array<string, array<string, true>>, pages: int}
 */
function restoreAll(Company $company, Device $device, int $limit): array
{
    $received = [];
    $cursor = 0;
    $pages = 0;
    $parents = ['client_measurements' => ['client_id' => 'clients'], 'orders' => ['client_id' => 'clients'],
        'order_payments' => ['order_id' => 'orders'], 'order_notes' => ['order_id' => 'orders']];

    do {
        $page = test()->postJson('/api/v1/sync/bootstrap', ['since' => $cursor, 'limit' => $limit], [
            'X-Company-Id' => $company->getKey(), 'X-Device-Id' => $device->getKey(),
        ])->assertOk()->json('data');
        $pages++;

        foreach ($page['changes'] as $entity => $rows) {
            foreach ($rows as $row) {
                $received[$entity][$row['id']] = true;
            }
        }

        foreach ($page['changes'] as $entity => $rows) {
            foreach ($parents[$entity] ?? [] as $column => $parent) {
                foreach ($rows as $row) {
                    $ref = $row['data'][$column] ?? null;
                    expect($ref === null || isset($received[$parent][$ref]))
                        ->toBeTrue("{$entity}.{$column} référence {$parent} {$ref} absent de la page {$pages}");
                }
            }
            if ($entity !== 'companies') {
                expect(isset($received['companies'][$company->getKey()]))->toBeTrue("atelier absent de la page {$pages}");
            }
        }

        $cursor = $page['cursor'];
    } while ($page['has_more']);

    return ['received' => $received, 'pages' => $pages];
}

describe('Restauration après réinstallation', function (): void {
    it('livre des pages autonomes même quand un parent a été modifié après ses enfants', function (): void {
        [$owner, $company] = restoreWorkshop();
        Sanctum::actingAs($owner);
        $phone1 = freshDevice($owner);
        $headers = ['X-Company-Id' => $company->getKey(), 'X-Device-Id' => $phone1->getKey()];

        $clients = [];
        $ops = [];
        foreach (range(1, 6) as $i) {
            $id = (string) Str::uuid();
            $clients[] = $id;
            $ops[] = restoreOp('clients', ['name' => "Client {$i}"], $id);
        }
        $this->postJson('/api/v1/sync/push', ['ops' => $ops], $headers)->assertOk();

        $orders = [];
        foreach ($clients as $i => $clientId) {
            $orderId = (string) Str::uuid();
            $orders[] = $orderId;
            $this->postJson('/api/v1/sync/push', ['ops' => [
                restoreOp('orders', ['client_id' => $clientId, 'order_code' => "CMD-{$i}", 'status' => 'new', 'price' => 1000, 'deposit' => 0], $orderId),
                restoreOp('order_payments', ['order_id' => $orderId, 'amount' => 500]),
            ]], $headers)->assertOk();
        }

        // Le premier client et la première commande sont corrigés après coup :
        // leurs révisions dépassent désormais celles de leurs enfants.
        $this->postJson('/api/v1/sync/push', ['ops' => [
            restoreOp('clients', ['name' => 'Client 1 corrigé', 'phone' => '+22670000001'], $clients[0]),
            restoreOp('orders', ['client_id' => $clients[0], 'order_code' => 'CMD-0', 'status' => 'in_progress', 'price' => 1200, 'deposit' => 0], $orders[0]),
        ]], $headers)->assertOk();
        $this->travel(1)->seconds();
        $company->forceFill(['name' => 'Atelier Alice (renommé)'])->save();

        // Téléphone neuf, pages minuscules pour forcer le découpage.
        $result = restoreAll($company, freshDevice($owner), 2);

        expect($result['pages'])->toBeGreaterThan(3)
            ->and(count($result['received']['clients']))->toBe(6)
            ->and(count($result['received']['orders']))->toBe(6)
            ->and(count($result['received']['order_payments']))->toBe(6);
    });

    it('retrouve tous les ateliers du compte, chacun avec ses seules données', function (): void {
        $alice = User::factory()->create();
        $workshops = [];
        foreach (['Atelier 1', 'Atelier 2', 'Atelier 3'] as $index => $name) {
            [, $company] = restoreWorkshop($name, $alice);
            Client::query()->create(['company_id' => $company->getKey(), 'name' => "Client de {$name}"]);
            $workshops[] = $company;
        }
        [, $foreign] = restoreWorkshop('Atelier de Bob');
        Client::query()->create(['company_id' => $foreign->getKey(), 'name' => 'Client de Bob']);

        Sanctum::actingAs($alice);
        $this->getJson('/api/v1/companies')->assertOk()->assertJsonCount(3, 'data');

        $device = freshDevice($alice);
        foreach ($workshops as $company) {
            $page = $this->postJson('/api/v1/sync/bootstrap', ['since' => 0, 'limit' => 500], [
                'X-Company-Id' => $company->getKey(), 'X-Device-Id' => $device->getKey(),
            ])->assertOk()->json('data.changes.clients');

            expect($page)->toHaveCount(1)
                ->and($page[0]['data']['name'])->toBe("Client de {$company->name}");
        }

        $this->postJson('/api/v1/sync/bootstrap', ['since' => 0], [
            'X-Company-Id' => $foreign->getKey(), 'X-Device-Id' => $device->getKey(),
        ])->assertForbidden();
    });

    it('ne joint jamais à un employé restreint le client d’une commande qui ne lui est pas affectée', function (): void {
        [$owner, $company] = restoreWorkshop();
        CompanySetting::query()->create(['company_id' => $company->getKey(), 'employee_restricted_orders' => true]);

        $tailor = User::factory()->create();
        $company->members()->attach($tailor->id, ['role' => Company::ROLE_TAILOR, 'joined_at' => now()]);
        $employee = Employee::query()->create(['company_id' => $company->getKey(), 'name' => 'Awa', 'status' => 'active']);
        $employee->forceFill(['user_id' => $tailor->id])->save();

        $mine = Client::query()->create(['company_id' => $company->getKey(), 'name' => 'Client affecté']);
        $other = Client::query()->create(['company_id' => $company->getKey(), 'name' => 'Client non affecté']);
        $assigned = Order::query()->create(['company_id' => $company->getKey(), 'client_id' => $mine->id, 'order_code' => 'A-1', 'status' => 'new', 'price' => 0, 'deposit' => 0]);
        Order::query()->create(['company_id' => $company->getKey(), 'client_id' => $other->id, 'order_code' => 'B-1', 'status' => 'new', 'price' => 0, 'deposit' => 0]);
        app(OrderAssignmentService::class)->assign($assigned, [$employee->id], $owner);

        // Les clients sont modifiés après les commandes : ils seraient joints comme parents.
        $mine->forceFill(['phone' => '1'])->save();
        $other->forceFill(['phone' => '2'])->save();

        Sanctum::actingAs($tailor);
        $result = restoreAll($company, freshDevice($tailor), 1);

        expect(array_keys($result['received']['clients'] ?? []))->toBe([$mine->id])
            ->and(array_keys($result['received']['orders'] ?? []))->toBe([$assigned->id]);
    });
});

describe('Adresse e-mail et casse', function (): void {
    it('retrouve le compte quelle que soit la casse saisie', function (): void {
        $this->postJson('/api/v1/register', [
            'name' => 'Alice', 'email' => 'Alice@Kudi.test',
            'password' => 'motdepasse123', 'password_confirmation' => 'motdepasse123',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'alice@kudi.test']);

        foreach (['Alice@Kudi.test', 'alice@kudi.test', ' ALICE@KUDI.TEST '] as $login) {
            $this->postJson('/api/v1/login', ['login' => $login, 'password' => 'motdepasse123'])->assertOk();
        }
    });

    it('refuse un second compte qui ne diffère que par la casse', function (): void {
        User::factory()->create(['email' => 'alice@kudi.test']);

        $this->postJson('/api/v1/register', [
            'name' => 'Alice bis', 'email' => 'ALICE@kudi.test',
            'password' => 'motdepasse123', 'password_confirmation' => 'motdepasse123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    });
});

describe('Modification de l’atelier depuis le téléphone', function (): void {
    it('accepte le renommage de l’atelier et le restitue au nouvel appareil', function (): void {
        [$owner, $company] = restoreWorkshop();
        Sanctum::actingAs($owner);
        $phone = freshDevice($owner);

        $this->travel(1)->seconds();
        $this->postJson('/api/v1/sync/push', ['ops' => [
            restoreOp('companies', ['name' => 'Atelier Alice Couture', 'address' => 'Ouagadougou', 'status' => 'active'], $company->getKey()),
        ]], ['X-Company-Id' => $company->getKey(), 'X-Device-Id' => $phone->getKey()])
            ->assertOk()
            ->assertJsonPath('data.summary.applied', 1)
            ->assertJsonPath('data.summary.rejected', 0);

        expect($company->refresh()->name)->toBe('Atelier Alice Couture');

        $page = $this->postJson('/api/v1/sync/bootstrap', ['since' => 0], [
            'X-Company-Id' => $company->getKey(), 'X-Device-Id' => freshDevice($owner)->getKey(),
        ])->assertOk()->json('data.changes.companies');

        expect($page[0]['data']['name'])->toBe('Atelier Alice Couture')
            ->and($page[0]['data']['address'])->toBe('Ouagadougou');
    });
});
