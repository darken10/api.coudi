<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\GarmentType;
use App\Models\GarmentTypeField;
use App\Models\MeasurementField;
use App\Models\User;
use App\Services\Catalog\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function catalogCompany(string $name = 'Atelier Catalogue'): Company
{
    return Company::factory()->create(['name' => $name]);
}

describe('Catalogue préconfiguré', function (): void {
    it('livre un catalogue complet à un nouvel atelier', function (): void {
        $company = catalogCompany();

        app(CatalogSeeder::class)->seed($company);

        expect(GarmentType::query()->where('company_id', $company->getKey())->count())->toBeGreaterThan(50)
            ->and(MeasurementField::query()->where('company_id', $company->getKey())->count())->toBeGreaterThan(30)
            ->and(GarmentTypeField::query()->where('company_id', $company->getKey())->count())->toBeGreaterThan(200);

        // Un vêtement précis possède bien ses champs associés.
        $boubou = GarmentType::query()->where('company_id', $company->getKey())->where('name', 'Boubou simple')->first();
        expect($boubou)->not->toBeNull()
            ->and($boubou->fields()->count())->toBeGreaterThan(0)
            ->and($boubou->category)->toBe('Homme');

        // Les catégories de champs sont renseignées.
        expect(MeasurementField::query()->where('company_id', $company->getKey())->where('name', 'Tour de poitrine')->first()->category)->toBe('Corps');
    });

    it('est idempotente : une seconde exécution ne crée rien', function (): void {
        $company = catalogCompany();
        $seeder = app(CatalogSeeder::class);

        $seeder->seed($company);
        $before = [
            'garments' => GarmentType::query()->where('company_id', $company->getKey())->count(),
            'fields' => MeasurementField::query()->where('company_id', $company->getKey())->count(),
            'associations' => GarmentTypeField::query()->where('company_id', $company->getKey())->count(),
        ];

        $counts = $seeder->seed($company);

        expect($counts)->toBe(['garments' => 0, 'fields' => 0, 'associations' => 0])
            ->and(GarmentType::query()->where('company_id', $company->getKey())->count())->toBe($before['garments'])
            ->and(MeasurementField::query()->where('company_id', $company->getKey())->count())->toBe($before['fields']);
    });

    it('isole les catalogues : renommer chez A ne change pas B', function (): void {
        $companyA = catalogCompany('Atelier A');
        $companyB = catalogCompany('Atelier B');
        $seeder = app(CatalogSeeder::class);

        $seeder->seed($companyA);
        $seeder->seed($companyB);

        GarmentType::query()
            ->where('company_id', $companyA->getKey())
            ->where('name', 'Boubou simple')
            ->update(['name' => 'Boubou traditionnel']);

        expect(GarmentType::query()->where('company_id', $companyA->getKey())->where('name', 'Boubou traditionnel')->exists())->toBeTrue()
            ->and(GarmentType::query()->where('company_id', $companyA->getKey())->where('name', 'Boubou simple')->exists())->toBeFalse()
            ->and(GarmentType::query()->where('company_id', $companyB->getKey())->where('name', 'Boubou simple')->exists())->toBeTrue();
    });

    it('ne réinitialise pas un catalogue déjà personnalisé', function (): void {
        $company = catalogCompany();
        $seeder = app(CatalogSeeder::class);

        $seeder->seed($company);

        // Personnalisation : un vêtement ajouté + une association retirée.
        GarmentType::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Robe sur mesure',
            'category' => 'Femme',
            'complexity' => 'simple',
            'is_active' => true,
        ]);

        $robe = GarmentType::query()->where('company_id', $company->getKey())->where('name', 'Robe simple')->first();
        $before = $robe->fields()->count();
        $robe->fields()->first()?->delete();

        $seeder->seed($company);

        expect(GarmentType::query()->where('company_id', $company->getKey())->where('name', 'Robe sur mesure')->exists())->toBeTrue()
            ->and($robe->fresh()->fields()->count())->toBe($before - 1);
    });

    it('initialise le catalogue à la création d’un atelier via l’API', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $companyId = $this->postJson('/api/v1/companies', ['name' => 'Atelier API'])
            ->assertCreated()
            ->json('data.id');

        expect(GarmentType::query()->where('company_id', $companyId)->count())->toBeGreaterThan(50)
            ->and(GarmentTypeField::query()->where('company_id', $companyId)->count())->toBeGreaterThan(200);
    });
});
