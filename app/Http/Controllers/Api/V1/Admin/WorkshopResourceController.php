<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Company;
use App\Models\Contracts\Replicable;
use App\Sync\SyncEntity;
use App\Sync\SyncRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Console de gestion métier d'un atelier.
 *
 * Les routes mobiles ne sont pas réutilisées ici : elles sont protégées par
 * l'appartenance de leur utilisateur à l'atelier. Cette façade, elle, est
 * réservée au super administrateur, mais utilise les mêmes modèles et donc les
 * mêmes révisions de synchronisation. Toute correction faite depuis le web est
 * ainsi récupérée par les téléphones au prochain pull.
 */
final class WorkshopResourceController extends ApiController
{
    /** GET /admin/companies/{company}/workspace */
    public function overview(Company $company): JsonResponse
    {
        $counts = [];

        foreach (SyncRegistry::all() as $key => $entity) {
            if ($key === 'companies') {
                continue;
            }

            $counts[$key] = $entity->newModel()->newQuery()
                ->where($entity->companyColumn(), $company->syncCompanyId())
                ->count();
        }

        return $this->success([
            'company_id' => $company->getKey(),
            'counts' => $counts,
        ]);
    }

    /** GET /admin/companies/{company}/resources/{entity} */
    public function index(Request $request, Company $company, string $entity): JsonResponse
    {
        $resource = $this->resource($entity);
        $query = $resource->newModel()->newQuery();

        if ($request->boolean('with_trashed')) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $query->where($resource->companyColumn(), $company->syncCompanyId());

        $search = $request->string('search')->trim()->value();
        if ($search !== '') {
            $columns = $this->searchableColumns($entity);
            if ($columns !== []) {
                $query->where(function ($nested) use ($columns, $search): void {
                    foreach ($columns as $column) {
                        $nested->orWhere($column, 'like', "%{$search}%");
                    }
                });
            }
        }

        return $this->success($query->latest('updated_at')->paginate($this->perPage($request)));
    }

    /** GET /admin/companies/{company}/resources/{entity}/{id} */
    public function show(Company $company, string $entity, string $id): JsonResponse
    {
        $model = $this->find($company, $this->resource($entity), $id);

        return $model === null ? $this->notFound() : $this->success($model);
    }

    /** POST /admin/companies/{company}/resources/{entity} */
    public function store(Request $request, Company $company, string $entity): JsonResponse
    {
        $resource = $this->resource($entity);
        $data = $this->data($request, $resource, true);
        $this->verifyParents($company, $resource, $data);

        /** @var Model&Replicable $model */
        $model = DB::transaction(function () use ($company, $resource, $data, $entity): Model {
            // Les réglages ont une ligne unique par atelier : « créer » devient
            // donc naturellement une mise à jour de cette ligne si elle existe.
            $model = $entity === 'company_settings'
                ? $resource->newModel()->newQuery()->firstOrNew(['company_id' => $company->syncCompanyId()])
                : $resource->newModel();

            $model->fill($data);
            $model->forceFill([$resource->companyColumn() => $company->syncCompanyId()]);
            $model->save();

            return $model;
        });

        return $this->created($model->refresh(), 'Enregistré.');
    }

    /** PUT /admin/companies/{company}/resources/{entity}/{id} */
    public function update(Request $request, Company $company, string $entity, string $id): JsonResponse
    {
        $resource = $this->resource($entity);
        $model = $this->find($company, $resource, $id);

        if ($model === null) {
            return $this->notFound();
        }

        $data = $this->data($request, $resource, false);
        $this->verifyParents($company, $resource, $data);

        DB::transaction(function () use ($model, $data): void {
            $model->fill($data)->save();
        });

        return $this->success($model->refresh(), 'Mis à jour.');
    }

    /** DELETE /admin/companies/{company}/resources/{entity}/{id} */
    public function destroy(Company $company, string $entity, string $id): JsonResponse
    {
        $model = $this->find($company, $this->resource($entity), $id);

        if ($model === null) {
            return $this->notFound();
        }

        DB::transaction(fn () => $model->markDeleted());

        return $this->success(message: 'Supprimé. Les appareils recevront cette suppression à leur prochaine synchronisation.');
    }

    /** POST /admin/companies/{company}/resources/{entity}/{id}/restore */
    public function restore(Company $company, string $entity, string $id): JsonResponse
    {
        $resource = $this->resource($entity);
        $model = $this->find($company, $resource, $id, true);

        if ($model === null) {
            return $this->notFound();
        }

        DB::transaction(fn () => $model->forceFill(['deleted_at' => null])->save());

        return $this->success($model->refresh(), 'Restauré.');
    }

    private function resource(string $key): SyncEntity
    {
        if ($key === 'companies' || ! SyncRegistry::has($key)) {
            abort(404);
        }

        return SyncRegistry::get($key);
    }

    /** @return (Model&Replicable)|null */
    private function find(Company $company, SyncEntity $resource, string $id, bool $withTrashed = false): ?Model
    {
        $query = $resource->newModel()->newQuery();
        if ($withTrashed) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        /** @var (Model&Replicable)|null $model */
        $model = $query->where($resource->companyColumn(), $company->syncCompanyId())->find($id);

        return $model;
    }

    /** @return array<string, mixed> */
    private function data(Request $request, SyncEntity $resource, bool $creating): array
    {
        $payload = $request->input('data', $request->all());
        if (! is_array($payload)) {
            throw ValidationException::withMessages(['data' => ['Les données doivent être un objet.']]);
        }

        $unknown = array_diff(array_keys($payload), $resource->fields);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['data' => ['Champ(s) non modifiable(s) : '.implode(', ', $unknown).'.']]);
        }

        $data = Arr::only($payload, $resource->fields);
        if ($creating && $data === []) {
            throw ValidationException::withMessages(['data' => ['Au moins un champ doit être renseigné.']]);
        }

        return $data;
    }

    /**
     * Vérifie les références avant l'écriture : un administrateur ne peut pas
     * rattacher une commande d'un atelier à un client d'un autre atelier.
     *
     * @param  array<string, mixed>  $data
     */
    private function verifyParents(Company $company, SyncEntity $resource, array $data): void
    {
        foreach ($resource->parents as $column => $parentKey) {
            if (! array_key_exists($column, $data) || $data[$column] === null || $data[$column] === '') {
                continue;
            }

            $parent = SyncRegistry::get($parentKey);
            $exists = $parent->newModel()->newQuery()
                ->where($parent->companyColumn(), $company->syncCompanyId())
                ->whereKey($data[$column])
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    "data.{$column}" => ['Cette référence est inconnue dans cet atelier.'],
                ]);
            }
        }
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->integer('per_page', 25), 1), 200);
    }

    /** @return list<string> */
    private function searchableColumns(string $entity): array
    {
        return match ($entity) {
            'clients', 'employees', 'garment_types', 'measurement_fields', 'model_galleries' => ['name'],
            'orders' => ['order_code', 'description', 'status'],
            'design_models' => ['name', 'reference', 'tags'],
            'media_assets' => ['file_name', 'caption'],
            'order_notes' => ['content'],
            default => [],
        };
    }
}
