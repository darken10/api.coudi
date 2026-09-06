<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workshop;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Sync\SyncEntity;
use App\Sync\SyncRegistry;
use App\Models\Contracts\Replicable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * CRUD REST d'une entité de l'atelier, pour la console web.
 *
 * Le mobile ne passe pas par ici : il ne parle qu'aux quatre points d'entrée de
 * synchronisation. Les deux chemins écrivent pourtant les mêmes lignes, donc
 * les mêmes règles s'appliquent — cloisonnement par atelier, révision prélevée
 * sur le compteur du locataire, suppression en pierre tombale.
 */
abstract class TenantResourceController extends ApiController
{
    use ResolvesTenant;

    /** Clé de l'entité au registre de synchronisation. */
    abstract protected function entityKey(): string;

    /**
     * @param  bool  $creating  Les règles d'une création sont plus strictes :
     *                          une mise à jour partielle n'exige pas le requis.
     * @return array<string, mixed>
     */
    abstract protected function rules(Request $request, bool $creating): array;

    /**
     * Champs validés, garantis exploitables par `fill()`.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate($this->rules($request, $creating));

        return $data;
    }

    /** GET /… */
    final public function index(Request $request): JsonResponse
    {
        $entity = $this->entity();

        $query = QueryBuilder::for($entity->model)
            ->allowedFilters(...$this->allowedFilters())
            ->allowedSorts(...$this->allowedSorts())
            ->defaultSort($this->defaultSort());

        // La corbeille n'est pas exposée par défaut : les pierres tombales sont
        // un mécanisme de synchronisation, pas une vue métier.
        if ($request->boolean('with_trashed')) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $results = $query
            ->where('company_id', $this->company($request)->syncCompanyId())
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return $this->success($results);
    }

    /** GET /…/{id} */
    final public function show(Request $request, string $id): JsonResponse
    {
        $model = $this->findOrNull($request, $id);

        return $model === null
            ? $this->notFound()
            : $this->success($model);
    }

    /** POST /… */
    final public function store(Request $request): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas la création.');
        }

        $data = $this->validated($request, true);
        $company = $this->company($request);

        /*
         * La transaction n'est pas décorative : le prélèvement d'une révision
         * et l'écriture qu'elle numérote doivent devenir visibles ensemble,
         * sinon un `pull` concurrent peut avancer son curseur par-dessus une
         * ligne pas encore lisible — et la perdre définitivement.
         */
        $model = DB::transaction(function () use ($data, $company): Model {
            $model = $this->entity()->newModel();
            $model->fill($data);
            $model->forceFill(['company_id' => $company->syncCompanyId()]);
            $model->save();

            return $model;
        });

        return $this->created($model);
    }

    /** PUT /…/{id} */
    final public function update(Request $request, string $id): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas la modification.');
        }

        $model = $this->findOrNull($request, $id);

        if ($model === null) {
            return $this->notFound();
        }

        $data = $this->validated($request, false);

        DB::transaction(function () use ($model, $data): void {
            $model->fill($data)->save();
        });

        return $this->success($model->refresh());
    }

    /** DELETE /…/{id} */
    final public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas la suppression.');
        }

        $model = $this->findOrNull($request, $id);

        if ($model === null) {
            return $this->notFound();
        }

        // Pierre tombale : un appareil hors ligne au moment de l'effacement
        // n'apprendrait jamais une suppression en dur.
        DB::transaction(function () use ($model): void {
            $model->markDeleted();
        });

        return $this->success(message: 'Supprimé.');
    }

    /** POST /…/{id}/restore */
    final public function restore(Request $request, string $id): JsonResponse
    {
        if (! $this->canWrite($request)) {
            return $this->forbidden('Votre rôle sur cet atelier ne permet pas la restauration.');
        }

        $model = $this->entity()->query()
            ->where('company_id', $this->company($request)->syncCompanyId())
            ->whereKey($id)
            ->first();

        if ($model === null) {
            return $this->notFound();
        }

        DB::transaction(function () use ($model): void {
            $model->forceFill(['deleted_at' => null])->save();
        });

        return $this->success($model->refresh(), 'Restauré.');
    }

    /**
     * Les filtres sont déclarés typés, jamais en noms nus : le défaut de Spatie
     * est un `LIKE %valeur%`, si bien qu'un filtre `status=active` ramènerait
     * aussi les `inactive`.
     *
     * @return list<AllowedFilter|string>
     */
    protected function allowedFilters(): array
    {
        return [AllowedFilter::partial('name')];
    }

    /** @return list<string> */
    protected function allowedSorts(): array
    {
        return ['name', 'created_at', 'updated_at'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function perPage(Request $request): int
    {
        return min(max((int) $request->integer('per_page', 25), 1), 200);
    }

    protected function entity(): SyncEntity
    {
        return SyncRegistry::get($this->entityKey());
    }

    /** @return (Model&Replicable)|null */
    protected function findOrNull(Request $request, string $id): ?Model
    {
        return $this->entity()->newModel()->newQuery()
            ->where('company_id', $this->company($request)->syncCompanyId())
            ->whereKey($id)
            ->first();
    }
}
