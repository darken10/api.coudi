<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * CRUD REST du référentiel — le chemin de la console web.
 *
 * Il écrit les mêmes lignes que la synchronisation : les vérifications portent
 * donc autant sur le résultat métier que sur le fait qu'une écriture web reste
 * visible des appareils (révision prélevée, effacement en pierre tombale).
 */
function crudWorkshop(string $role = Company::ROLE_OWNER): array
{
    $user = User::factory()->create();

    $company = new Company;
    $company->fill(['name' => 'Atelier Fatou']);
    $company->save();

    $company->members()->attach($user->id, ['role' => $role, 'joined_at' => now()]);

    Sanctum::actingAs($user);

    return [$user, $company, ['X-Company-Id' => $company->getKey()]];
}

describe('Ateliers', function (): void {
    it('enregistre un atelier créé hors ligne en gardant son identifiant local', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $localId = (string) Str::uuid();

        $response = $this->postJson('/api/v1/companies', [
            'id' => $localId,
            'name' => 'Atelier Kadi',
            'owner_name' => 'Kadidiatou',
        ])->assertCreated();

        expect($response->json('data.id'))->toBe($localId)
            ->and($response->json('data.role'))->toBe(Company::ROLE_OWNER);

        // Les réglages doivent exister dès la création, sinon le premier
        // chargement livre une application sans devise.
        $this->assertDatabaseHas('company_settings', ['company_id' => $localId]);
        $this->assertDatabaseHas('company_user', ['company_id' => $localId, 'user_id' => $user->id]);
    });

    it('ne liste que les ateliers du compte', function (): void {
        [, $company] = crudWorkshop();

        $voisin = new Company;
        $voisin->fill(['name' => 'Atelier voisin']);
        $voisin->save();

        $response = $this->getJson('/api/v1/companies')->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($company->getKey());
    });
});

describe('Clients', function (): void {
    it('crée, liste, modifie et efface', function (): void {
        [, $company, $headers] = crudWorkshop();

        $id = $this->postJson('/api/v1/clients', [
            'name' => 'Aminata Diallo',
            'phone' => '70000000',
            'is_vip' => true,
        ], $headers)->assertCreated()->json('data.id');

        $this->getJson('/api/v1/clients', $headers)
            ->assertOk()
            ->assertJsonPath('data.data.0.name', 'Aminata Diallo');

        $this->putJson("/api/v1/clients/{$id}", ['name' => 'Aminata Diallo Traoré'], $headers)
            ->assertOk()
            ->assertJsonPath('data.name', 'Aminata Diallo Traoré');

        $this->deleteJson("/api/v1/clients/{$id}", [], $headers)->assertOk();

        // Effacement en pierre tombale : la ligne survit pour que les appareils
        // hors ligne puissent apprendre la suppression.
        $this->assertDatabaseHas('clients', ['id' => $id]);
        $this->getJson("/api/v1/clients/{$id}", $headers)->assertNotFound();

        $this->postJson("/api/v1/clients/{$id}/restore", [], $headers)->assertOk();
        $this->getJson("/api/v1/clients/{$id}", $headers)->assertOk();
    });

    it("fait avancer le compteur de l'atelier à chaque écriture", function (): void {
        [, $company, $headers] = crudWorkshop();

        $avant = $company->fresh()->sync_revision;

        $this->postJson('/api/v1/clients', ['name' => 'Awa'], $headers)->assertCreated();

        expect($company->fresh()->sync_revision)->toBeGreaterThan($avant);
    });

    it("n'expose jamais le carnet d'un autre atelier", function (): void {
        [, , $headers] = crudWorkshop();

        $voisin = new Company;
        $voisin->fill(['name' => 'Atelier voisin']);
        $voisin->save();

        $etranger = Client::query()->create([
            'company_id' => $voisin->getKey(),
            'name' => 'Client du voisin',
        ]);

        $this->getJson('/api/v1/clients', $headers)->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson("/api/v1/clients/{$etranger->getKey()}", $headers)->assertNotFound();
    });

    it('refuse la création à un tailleur', function (): void {
        [, , $headers] = crudWorkshop(Company::ROLE_TAILOR);

        $this->postJson('/api/v1/clients', ['name' => 'Refusé'], $headers)->assertForbidden();
        $this->getJson('/api/v1/clients', $headers)->assertOk();
    });

    it('valide les entrées', function (): void {
        [, , $headers] = crudWorkshop();

        $this->postJson('/api/v1/clients', ['email' => 'pas-une-adresse'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);
    });
});

describe('Référentiel', function (): void {
    it('trie les champs de mesure par ordre d\'affichage', function (): void {
        [, , $headers] = crudWorkshop();

        foreach ([['Taille', 2], ['Poitrine', 1], ['Hanches', 3]] as [$name, $order]) {
            $this->postJson('/api/v1/measurement-fields', [
                'name' => $name,
                'sort_order' => $order,
            ], $headers)->assertCreated();
        }

        $noms = collect($this->getJson('/api/v1/measurement-fields', $headers)->json('data.data'))
            ->pluck('name')
            ->all();

        expect($noms)->toBe(['Poitrine', 'Taille', 'Hanches']);
    });

    it('filtre les employés par statut', function (): void {
        [, , $headers] = crudWorkshop();

        $this->postJson('/api/v1/employees', ['name' => 'Ibrahim', 'status' => 'active'], $headers);
        $this->postJson('/api/v1/employees', ['name' => 'Salif', 'status' => 'inactive'], $headers);

        $this->getJson('/api/v1/employees?filter[status]=active', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.name', 'Ibrahim');
    });

    it('crée un type de vêtement avec son tarif de base', function (): void {
        [, , $headers] = crudWorkshop();

        $this->postJson('/api/v1/garment-types', [
            'name' => 'Boubou brodé',
            'complexity' => 'complex',
            'base_price' => 25000,
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.base_price', 25000);
    });
});
