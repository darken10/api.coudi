<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Company;
use App\Models\Device;
use App\Models\GarmentType;
use App\Models\MeasurementField;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Monte un atelier complet : un compte propriétaire, l'atelier, un appareil
 * enregistré, et la session Sanctum correspondante.
 *
 * @return array{0: User, 1: Company, 2: Device}
 */
function workshop(string $name = 'Atelier Fatou'): array
{
    $user = User::factory()->create();

    $company = new Company;
    $company->fill(['name' => $name, 'status' => Company::STATUS_ACTIVE]);
    $company->save();

    $company->members()->attach($user->id, [
        'role' => Company::ROLE_OWNER,
        'joined_at' => now(),
    ]);

    $device = Device::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'platform' => 'android',
    ]);

    Sanctum::actingAs($user);

    return [$user, $company, $device];
}

/** @return array<string, string> */
function syncHeaders(Company $company, Device $device): array
{
    return [
        'X-Company-Id' => $company->getKey(),
        'X-Device-Id' => $device->getKey(),
    ];
}

/** @return array<string, mixed> */
function op(string $entity, array $data, string $op = 'upsert', ?string $id = null): array
{
    return [
        'op_id' => (string) Str::uuid(),
        'entity' => $entity,
        'op' => $op,
        'id' => $id ?? (string) Str::uuid(),
        'updated_at' => now()->toIso8601String(),
        'data' => $data,
    ];
}

describe('Cloisonnement', function (): void {
    it("refuse un atelier auquel le compte n'appartient pas", function (): void {
        [, , $device] = workshop();

        $etranger = new Company;
        $etranger->fill(['name' => 'Atelier voisin']);
        $etranger->save();

        $this->postJson('/api/v1/sync/pull', ['since' => 0], [
            'X-Company-Id' => $etranger->getKey(),
            'X-Device-Id' => $device->getKey(),
        ])->assertForbidden();
    });

    it("exige l'en-tête d'atelier", function (): void {
        [, , $device] = workshop();

        $this->postJson('/api/v1/sync/pull', ['since' => 0], [
            'X-Device-Id' => $device->getKey(),
        ])->assertStatus(400);
    });

    it("refuse l'appareil enregistré sur un autre compte", function (): void {
        [, $company] = workshop();

        $autre = User::factory()->create();
        $device = Device::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $autre->id,
        ]);

        $this->postJson('/api/v1/sync/pull', ['since' => 0], syncHeaders($company, $device))
            ->assertForbidden();
    });

    it("enregistre l'appareil au premier contact", function (): void {
        [$user, $company] = workshop();
        $neuf = (string) Str::uuid();

        $this->postJson('/api/v1/sync/pull', ['since' => 0], [
            'X-Company-Id' => $company->getKey(),
            'X-Device-Id' => $neuf,
        ])->assertOk();

        $this->assertDatabaseHas('devices', ['id' => $neuf, 'user_id' => $user->id]);
    });
});

describe('Push', function (): void {
    it('crée un client et lui attribue une révision', function (): void {
        [, $company, $device] = workshop();

        $response = $this->postJson('/api/v1/sync/push', [
            'ops' => [op('clients', ['name' => 'Aminata Diallo', 'phone' => '70000000'])],
        ], syncHeaders($company, $device))->assertOk();

        $result = $response->json('data.results.0');

        expect($result['status'])->toBe('applied')
            ->and($result['revision'])->toBeGreaterThan(0);

        $this->assertDatabaseHas('clients', [
            'id' => $result['id'],
            'company_id' => $company->getKey(),
            'name' => 'Aminata Diallo',
            'last_device_id' => $device->getKey(),
        ]);
    });

    it("ignore l'atelier annoncé par l'appareil et impose celui de la session", function (): void {
        [, $company, $device] = workshop();

        $voisin = new Company;
        $voisin->fill(['name' => 'Atelier voisin']);
        $voisin->save();

        $response = $this->postJson('/api/v1/sync/push', [
            'ops' => [op('clients', ['name' => 'Injection', 'company_id' => $voisin->getKey()])],
        ], syncHeaders($company, $device))->assertOk();

        $this->assertDatabaseHas('clients', [
            'id' => $response->json('data.results.0.id'),
            'company_id' => $company->getKey(),
        ]);
    });

    it('ne rejoue pas une opération déjà appliquée', function (): void {
        [, $company, $device] = workshop();

        $op = op('order_payments', []);
        $payload = ['ops' => [$op]];

        // Première tentative : l'écriture passe, mais la réponse se perd.
        $client = Client::query()->create(['company_id' => $company->getKey(), 'name' => 'Awa']);
        $order = Order::query()->create([
            'company_id' => $company->getKey(),
            'client_id' => $client->getKey(),
            'order_code' => 'AB-0001',
        ]);

        $op['data'] = ['order_id' => $order->getKey(), 'amount' => 5000];
        $payload = ['ops' => [$op]];

        $this->postJson('/api/v1/sync/push', $payload, syncHeaders($company, $device))->assertOk();

        // L'appareil rejoue le même lot faute de réponse.
        $rejeu = $this->postJson('/api/v1/sync/push', $payload, syncHeaders($company, $device))->assertOk();

        expect($rejeu->json('data.results.0.replayed'))->toBeTrue()
            ->and(OrderPayment::query()->count())->toBe(1);
    });

    it('reporte une ligne dont le parent manque encore', function (): void {
        [, $company, $device] = workshop();

        $response = $this->postJson('/api/v1/sync/push', [
            'ops' => [op('orders', [
                'client_id' => (string) Str::uuid(),
                'order_code' => 'AB-0001',
            ])],
        ], syncHeaders($company, $device))->assertOk();

        $result = $response->json('data.results.0');

        expect($result['status'])->toBe('deferred')
            ->and($result['missing']['entity'])->toBe('clients');

        // Un report n'est pas terminal : rien ne doit être mémorisé, sinon le
        // rejeu renverrait éternellement le même report.
        $this->assertDatabaseCount('sync_operations', 0);
    });

    it('applique un lot dans l\'ordre parent puis enfant', function (): void {
        [, $company, $device] = workshop();

        $clientId = (string) Str::uuid();
        $orderId = (string) Str::uuid();

        // L'appareil envoie délibérément l'enfant en premier.
        $response = $this->postJson('/api/v1/sync/push', [
            'ops' => [
                op('orders', ['client_id' => $clientId, 'order_code' => 'AB-0007'], id: $orderId),
                op('clients', ['name' => 'Mariam'], id: $clientId),
            ],
        ], syncHeaders($company, $device))->assertOk();

        expect($response->json('data.summary.applied'))->toBe(2)
            ->and($response->json('data.summary.deferred'))->toBe(0);

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'client_id' => $clientId]);
    });

    it('renumérote un code de commande déjà pris', function (): void {
        [, $company, $device] = workshop();

        $client = Client::query()->create(['company_id' => $company->getKey(), 'name' => 'Awa']);
        Order::query()->create([
            'company_id' => $company->getKey(),
            'client_id' => $client->getKey(),
            'order_code' => 'AB-0042',
        ]);

        $response = $this->postJson('/api/v1/sync/push', [
            'ops' => [op('orders', [
                'client_id' => $client->getKey(),
                'order_code' => 'AB-0042',
            ])],
        ], syncHeaders($company, $device))->assertOk();

        $result = $response->json('data.results.0');

        expect($result['status'])->toBe('applied')
            ->and($result['changes']['order_code'])->toBe('AB-0042-2');
    });

    it('rend la version serveur quand elle est plus récente', function (): void {
        [, $company, $device] = workshop();

        $client = Client::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Version serveur',
        ]);

        $response = $this->postJson('/api/v1/sync/push', [
            'ops' => [[
                'op_id' => (string) Str::uuid(),
                'entity' => 'clients',
                'op' => 'upsert',
                'id' => $client->getKey(),
                'updated_at' => now()->subDay()->toIso8601String(),
                'data' => ['name' => 'Version mobile périmée'],
            ]],
        ], syncHeaders($company, $device))->assertOk();

        $result = $response->json('data.results.0');

        expect($result['status'])->toBe('conflict')
            ->and($result['server']['name'])->toBe('Version serveur');

        expect($client->refresh()->name)->toBe('Version serveur');
    });

    it('rapproche une mesure sur sa clé naturelle plutôt que sur son identifiant', function (): void {
        [, $company, $device] = workshop();

        $client = Client::query()->create(['company_id' => $company->getKey(), 'name' => 'Awa']);
        $type = GarmentType::query()->create(['company_id' => $company->getKey(), 'name' => 'Boubou']);
        $champ = MeasurementField::query()->create(['company_id' => $company->getKey(), 'name' => 'Poitrine']);

        $base = [
            'client_id' => $client->getKey(),
            'garment_type_id' => $type->getKey(),
            'field_id' => $champ->getKey(),
        ];

        // Deux téléphones relèvent la même mesure hors ligne : deux UUID pour
        // une seule ligne possible.
        $premier = $this->postJson('/api/v1/sync/push', [
            'ops' => [op('client_measurements', $base + ['value' => '92'])],
        ], syncHeaders($company, $device))->assertOk();

        $second = $this->postJson('/api/v1/sync/push', [
            'ops' => [op('client_measurements', $base + ['value' => '94'])],
        ], syncHeaders($company, $device))->assertOk();

        expect($second->json('data.results.0.canonical_id'))
            ->toBe($premier->json('data.results.0.id'))
            ->and($this->getConnection()->table('client_measurements')->count())->toBe(1);
    });

    it("n'écrase jamais un encaissement déjà enregistré", function (): void {
        [, $company, $device] = workshop();

        $client = Client::query()->create(['company_id' => $company->getKey(), 'name' => 'Awa']);
        $order = Order::query()->create([
            'company_id' => $company->getKey(),
            'client_id' => $client->getKey(),
            'order_code' => 'AB-0100',
        ]);

        // Deux acomptes distincts saisis sur deux téléphones : deux paiements,
        // jamais un écrasement.
        $this->postJson('/api/v1/sync/push', [
            'ops' => [
                op('order_payments', ['order_id' => $order->getKey(), 'amount' => 5000]),
                op('order_payments', ['order_id' => $order->getKey(), 'amount' => 3000]),
            ],
        ], syncHeaders($company, $device))->assertOk();

        expect(OrderPayment::query()->sum('amount'))->toEqual(8000.0);
    });

    it('refuse le push à un rôle sans droit d\'écriture', function (): void {
        [$user, $company, $device] = workshop();

        $company->members()->updateExistingPivot($user->id, ['role' => Company::ROLE_TAILOR]);

        $this->postJson('/api/v1/sync/push', [
            'ops' => [op('clients', ['name' => 'Refusé'])],
        ], syncHeaders($company, $device))->assertForbidden();
    });
});

describe('Pull', function (): void {
    it('ne renvoie que ce qui suit le curseur', function (): void {
        [, $company, $device] = workshop();

        Client::query()->create(['company_id' => $company->getKey(), 'name' => 'Premier']);

        $premier = $this->postJson('/api/v1/sync/pull', ['since' => 0], syncHeaders($company, $device))
            ->assertOk();

        $curseur = $premier->json('data.cursor');

        Client::query()->create(['company_id' => $company->getKey(), 'name' => 'Second']);

        $second = $this->postJson('/api/v1/sync/pull', ['since' => $curseur], syncHeaders($company, $device))
            ->assertOk();

        expect($second->json('data.changes.clients'))->toHaveCount(1)
            ->and($second->json('data.changes.clients.0.data.name'))->toBe('Second');
    });

    it('transmet les effacements comme des pierres tombales', function (): void {
        [, $company, $device] = workshop();

        $client = Client::query()->create(['company_id' => $company->getKey(), 'name' => 'À effacer']);

        $curseur = $this->postJson('/api/v1/sync/pull', ['since' => 0], syncHeaders($company, $device))
            ->json('data.cursor');

        $client->markDeleted();

        $response = $this->postJson('/api/v1/sync/pull', ['since' => $curseur], syncHeaders($company, $device))
            ->assertOk();

        expect($response->json('data.changes.clients.0.op'))->toBe('delete')
            ->and($response->json('data.changes.clients.0.id'))->toBe($client->getKey());
    });

    it('ne franchit pas la borne d\'une entité tronquée', function (): void {
        [, $company, $device] = workshop();

        foreach (range(1, 5) as $i) {
            Client::query()->create(['company_id' => $company->getKey(), 'name' => "Client {$i}"]);
        }

        GarmentType::query()->create(['company_id' => $company->getKey(), 'name' => 'Boubou']);

        $response = $this->postJson('/api/v1/sync/pull', ['since' => 0, 'limit' => 2], syncHeaders($company, $device))
            ->assertOk();

        expect($response->json('data.has_more'))->toBeTrue()
            ->and($response->json('data.changes.clients'))->toHaveCount(2);

        // Le type de vêtement est postérieur à la borne des clients : il ne doit
        // pas être livré maintenant, sinon le curseur sauterait par-dessus les
        // clients restants.
        expect($response->json('data.changes.garment_types'))->toBeNull();

        // Le rattrapage finit par tout livrer.
        $curseur = $response->json('data.cursor');
        $vu = 2;

        while ($curseur < $response->json('data.server_revision') && $vu < 20) {
            $page = $this->postJson('/api/v1/sync/pull', ['since' => $curseur, 'limit' => 2], syncHeaders($company, $device))
                ->assertOk();
            $curseur = $page->json('data.cursor');
            $vu += count($page->json('data.changes.clients') ?? []);

            if (! $page->json('data.has_more')) {
                break;
            }
        }

        expect($vu)->toBe(5);
    });

    it('ne mélange jamais deux ateliers', function (): void {
        [$user, $company, $device] = workshop();

        $autre = new Company;
        $autre->fill(['name' => 'Atelier B']);
        $autre->save();
        $autre->members()->attach($user->id, ['role' => Company::ROLE_OWNER, 'joined_at' => now()]);

        Client::query()->create(['company_id' => $company->getKey(), 'name' => 'Chez A']);
        Client::query()->create(['company_id' => $autre->getKey(), 'name' => 'Chez B']);

        $response = $this->postJson('/api/v1/sync/pull', ['since' => 0], syncHeaders($company, $device))
            ->assertOk();

        expect($response->json('data.changes.clients'))->toHaveCount(1)
            ->and($response->json('data.changes.clients.0.data.name'))->toBe('Chez A');
    });
});

describe('Bootstrap', function (): void {
    it('livre le référentiel complet et date la fin du chargement', function (): void {
        [, $company, $device] = workshop();

        Client::query()->create(['company_id' => $company->getKey(), 'name' => 'Awa']);
        GarmentType::query()->create(['company_id' => $company->getKey(), 'name' => 'Boubou']);
        MeasurementField::query()->create(['company_id' => $company->getKey(), 'name' => 'Poitrine']);

        $response = $this->postJson('/api/v1/sync/bootstrap', [], syncHeaders($company, $device))
            ->assertOk();

        expect($response->json('data.has_more'))->toBeFalse()
            ->and($response->json('data.totals.clients'))->toBe(1)
            ->and($response->json('data.changes.companies'))->toHaveCount(1);

        $this->assertDatabaseHas('device_sync_states', [
            'device_id' => $device->getKey(),
            'company_id' => $company->getKey(),
            'last_pulled_revision' => $response->json('data.cursor'),
        ]);

        expect($this->getConnection()->table('device_sync_states')
            ->where('device_id', $device->getKey())->value('bootstrapped_at'))->not->toBeNull();
    });
});

describe('Statut', function (): void {
    it("dit de combien de révisions l'appareil est en retard", function (): void {
        [, $company, $device] = workshop();

        Client::query()->create(['company_id' => $company->getKey(), 'name' => 'Awa']);

        $response = $this->getJson('/api/v1/sync/status', syncHeaders($company, $device))->assertOk();

        expect($response->json('data.behind'))->toBeGreaterThan(0)
            ->and($response->json('data.device_revision'))->toBe(0)
            ->and($response->json('data.can_write'))->toBeTrue();
    });
});

describe('Cascade des effacements', function (): void {
    it('efface aussi ce qui dépendait de la ligne supprimée', function (): void {
        [, $company, $device] = workshop();

        $employee = App\Models\Employee::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Ibrahim',
        ]);
        $type = GarmentType::query()->create(['company_id' => $company->getKey(), 'name' => 'Boubou']);
        $rate = App\Models\EmployeePieceRate::query()->create([
            'company_id' => $company->getKey(),
            'employee_id' => $employee->getKey(),
            'garment_type_id' => $type->getKey(),
            'rate' => 2500,
        ]);

        $response = $this->postJson('/api/v1/sync/push', [
            'ops' => [op('employees', [], 'delete', $employee->getKey())],
        ], syncHeaders($company, $device))->assertOk();

        expect($response->json('data.results.0.cascaded'))->toBe(1);

        // Sans cette pierre tombale, une réinstallation retéléchargerait un
        // tarif rattaché à un employé qui n'existe plus.
        expect($rate->fresh()->deleted_at)->not->toBeNull()
            ->and($type->fresh()->deleted_at)->toBeNull();
    });

    it("efface tout l'atelier quand l'atelier lui-même est supprimé", function (): void {
        [, $company, $device] = workshop();

        $client = Client::query()->create(['company_id' => $company->getKey(), 'name' => 'Awa']);
        $type = GarmentType::query()->create(['company_id' => $company->getKey(), 'name' => 'Boubou']);

        $this->postJson('/api/v1/sync/push', [
            'ops' => [op('companies', [], 'delete', $company->getKey())],
        ], syncHeaders($company, $device))->assertOk();

        expect($client->fresh()->deleted_at)->not->toBeNull()
            ->and($type->fresh()->deleted_at)->not->toBeNull();
    });
});
