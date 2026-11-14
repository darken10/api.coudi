<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Catalog\DefaultCatalog;
use App\Models\Company;
use App\Models\GarmentType;
use App\Models\GarmentTypeField;
use App\Models\MeasurementField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Initialise le catalogue d'un atelier à partir des données par défaut.
 *
 * Chaque atelier possède ses propres lignes (scopées par `company_id`) : le
 * catalogue est copié, jamais partagé. Une modification locale n'a donc aucun
 * effet sur un autre atelier ni sur le catalogue global.
 *
 * L'identification des éléments passe par une clé stable (`catalog_key`, un
 * slug du nom d'origine) plutôt que par le nom affiché : un atelier peut ainsi
 * renommer « Boubou simple » en « Boubou traditionnel » sans que le backfill le
 * recrée en doublon. On n'écrase et on ne supprime jamais rien.
 */
final class CatalogSeeder
{
    /** Nombre de champs créés — sert au retour des commandes. */
    public function seed(Company $company): array
    {
        return DB::transaction(function () use ($company): array {
            $companyId = $company->syncCompanyId();

            $beforeFields = MeasurementField::query()->where('company_id', $companyId)->count();
            $fieldIds = $this->seedMeasurementFields($companyId);
            $fieldsCreated = count($fieldIds) - $beforeFields;

            $beforeGarments = GarmentType::query()->where('company_id', $companyId)->count();
            $associationsCreated = $this->seedGarmentTypes($companyId, $fieldIds);
            $garmentsCreated = GarmentType::query()->where('company_id', $companyId)->count() - $beforeGarments;

            return [
                'garments' => $garmentsCreated,
                'fields' => $fieldsCreated,
                'associations' => $associationsCreated,
            ];
        });
    }

    /**
     * @return array<string, string>  clé de catalogue (slug) => identifiant
     */
    private function seedMeasurementFields(string $companyId): array
    {
        $rows = MeasurementField::query()
            ->where('company_id', $companyId)
            ->withTrashed()
            ->get(['id', 'name', 'catalog_key']);

        $byKey = [];
        $byName = [];
        $ids = [];

        foreach ($rows as $row) {
            $ids[Str::slug($row->name)] = $row->getKey();
            if ($row->catalog_key !== null) {
                $byKey[$row->catalog_key] = $row->getKey();
            } else {
                $byName[$row->name] = $row;
            }
        }

        $sort = $rows->count();

        foreach (DefaultCatalog::measurementFields() as $field) {
            $key = Str::slug($field['name']);

            if (isset($byKey[$key])) {
                $ids[$key] = $byKey[$key];
                continue;
            }

            // Ligne héritée (avant catalog_key) : on l'adopte sans la recréer.
            if (isset($byName[$field['name']])) {
                $legacy = $byName[$field['name']];
                $legacy->forceFill(['catalog_key' => $key])->save();
                $ids[$key] = $legacy->getKey();
                continue;
            }

            $model = MeasurementField::query()->create([
                'company_id' => $companyId,
                'name' => $field['name'],
                'catalog_key' => $key,
                'category' => $field['category'],
                'sort_order' => $sort++,
            ]);

            $ids[$key] = $model->getKey();
        }

        return $ids;
    }

    /**
     * @param  array<string, string>  $fieldIds  clé => identifiant
     */
    private function seedGarmentTypes(string $companyId, array $fieldIds): int
    {
        $rows = GarmentType::query()
            ->where('company_id', $companyId)
            ->withTrashed()
            ->get(['id', 'name', 'catalog_key']);

        $byKey = [];
        $byName = [];
        $garmentIds = [];

        foreach ($rows as $row) {
            $garmentIds[Str::slug($row->name)] = $row->getKey();
            if ($row->catalog_key !== null) {
                $byKey[$row->catalog_key] = $row->getKey();
            } else {
                $byName[$row->name] = $row;
            }
        }

        foreach (DefaultCatalog::garmentTypes() as $garment) {
            $key = Str::slug($garment['name']);

            if (isset($byKey[$key])) {
                $garmentIds[$key] = $byKey[$key];
                continue;
            }

            if (isset($byName[$garment['name']])) {
                $legacy = $byName[$garment['name']];
                $legacy->forceFill(['catalog_key' => $key])->save();
                $garmentIds[$key] = $legacy->getKey();
                continue;
            }

            $model = GarmentType::query()->create([
                'company_id' => $companyId,
                'name' => $garment['name'],
                'catalog_key' => $key,
                'category' => $garment['category'],
                'complexity' => $garment['complexity'],
                'is_active' => true,
            ]);

            $garmentIds[$key] = $model->getKey();
        }

        // Associations existantes : (garment_type_id => list(field_id)).
        // `withTrashed` : une association supprimée par l'utilisateur doit
        // rester absente, sans être recréée (ce qui violerait l'unicité).
        $linksByGarment = GarmentTypeField::query()
            ->where('company_id', $companyId)
            ->withTrashed()
            ->get(['garment_type_id', 'field_id'])
            ->groupBy('garment_type_id')
            ->map(fn ($rows) => $rows->pluck('field_id')->all())
            ->all();

        $associationsCreated = 0;

        foreach (DefaultCatalog::garmentTypes() as $garment) {
            $garmentId = $garmentIds[Str::slug($garment['name'])] ?? null;
            if ($garmentId === null) {
                continue;
            }

            $already = $linksByGarment[$garmentId] ?? [];

            foreach (array_values(array_unique($garment['fields'])) as $index => $fieldName) {
                $fieldId = $fieldIds[Str::slug($fieldName)] ?? null;
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

                $linksByGarment[$garmentId][] = $fieldId;
                $associationsCreated++;
            }
        }

        return $associationsCreated;
    }
}
