<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Catalog\DefaultCatalog;
use App\Models\Company;
use App\Models\GarmentType;
use App\Models\GarmentTypeField;
use App\Models\MeasurementField;
use Illuminate\Support\Facades\DB;

/**
 * Initialise le catalogue d'un atelier à partir des données par défaut.
 *
 * Chaque atelier possède ses propres lignes (scopées par `company_id`) : le
 * catalogue est copié, jamais partagé. Une modification locale n'a donc aucun
 * effet sur un autre atelier ni sur le catalogue global.
 *
 * L'initialisation est idempotente : on ne crée que les éléments dont le nom
 * n'existe pas déjà dans l'atelier. On n'écrase et on ne supprime jamais rien.
 */
final class CatalogSeeder
{
    /** Nombre de champs créés — sert au retour des commandes. */
    public function seed(Company $company): array
    {
        return DB::transaction(function () use ($company): array {
            $companyId = $company->syncCompanyId();

            $beforeFields = MeasurementField::query()->where('company_id', $companyId)->count();
            $fieldIds = $this->seedMeasurementFields($company, $companyId);
            $fieldsCreated = count($fieldIds) - $beforeFields;

            $beforeGarments = GarmentType::query()->where('company_id', $companyId)->count();
            $associationsCreated = $this->seedGarmentTypes($company, $companyId, $fieldIds);
            $garmentsCreated = GarmentType::query()->where('company_id', $companyId)->count() - $beforeGarments;

            return [
                'garments' => $garmentsCreated,
                'fields' => $fieldsCreated,
                'associations' => $associationsCreated,
            ];
        });
    }

    /**
     * @return array<string, string>  nom du champ => identifiant
     */
    private function seedMeasurementFields(Company $company, string $companyId): array
    {
        $existing = MeasurementField::query()
            ->where('company_id', $companyId)
            ->pluck('id', 'name')
            ->all();

        $ids = $existing;
        $sort = count($existing);

        foreach (DefaultCatalog::measurementFields() as $field) {
            if (array_key_exists($field['name'], $ids)) {
                continue;
            }

            $model = MeasurementField::query()->create([
                'company_id' => $companyId,
                'name' => $field['name'],
                'category' => $field['category'],
                'sort_order' => $sort++,
            ]);

            $ids[$field['name']] = $model->getKey();
        }

        return $ids;
    }

    /**
     * @param  array<string, string>  $fieldIds
     */
    private function seedGarmentTypes(Company $company, string $companyId, array $fieldIds): int
    {
        $existingGarments = GarmentType::query()
            ->where('company_id', $companyId)
            ->pluck('id', 'name')
            ->all();

        $garmentIds = $existingGarments;

        foreach (DefaultCatalog::garmentTypes() as $garment) {
            if (! array_key_exists($garment['name'], $garmentIds)) {
                $model = GarmentType::query()->create([
                    'company_id' => $companyId,
                    'name' => $garment['name'],
                    'category' => $garment['category'],
                    'complexity' => $garment['complexity'],
                    'is_active' => true,
                ]);

                $garmentIds[$garment['name']] = $model->getKey();
            }
        }

        // Associations existantes : (garment_type_id => set(field_id)).
        $existingLinks = GarmentTypeField::query()
            ->where('company_id', $companyId)
            ->get(['garment_type_id', 'field_id'])
            ->groupBy('garment_type_id')
            ->map(fn ($rows) => $rows->pluck('field_id')->all())
            ->all();

        $associationsCreated = 0;

        foreach (DefaultCatalog::garmentTypes() as $garment) {
            $garmentId = $garmentIds[$garment['name']] ?? null;
            if ($garmentId === null) {
                continue;
            }

            $already = $existingLinks[$garmentId] ?? [];

            foreach (array_values(array_unique($garment['fields'])) as $index => $fieldName) {
                $fieldId = $fieldIds[$fieldName] ?? null;
                if ($fieldId === null) {
                    continue;
                }

                if (in_array($fieldId, $already, true)) {
                    continue;
                }

                GarmentTypeField::query()->create([
                    'company_id' => $companyId,
                    'garment_type_id' => $garmentId,
                    'field_id' => $fieldId,
                    'sort_order' => $index,
                ]);

                $already[] = $fieldId;
                $associationsCreated++;
            }
        }

        return $associationsCreated;
    }
}
