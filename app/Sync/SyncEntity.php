<?php

declare(strict_types=1);

namespace App\Sync;

use App\Models\Contracts\Replicable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Description d'une entité répliquée : ce qui voyage, d'où vient son atelier,
 * de quoi elle dépend, et comment on arbitre ses conflits.
 */
final readonly class SyncEntity
{
    /**
     * @param  string  $key  Nom transporté dans le protocole (`clients`, `orders`…).
     * @param  class-string<Model&Replicable>  $model
     * @param  list<string>  $fields  Colonnes répliquées, hors socle de synchronisation.
     * @param  array<string, string>  $parents  Colonne de rattachement => entité parente.
     * @param  list<string>  $naturalKey  Uplet unique, pour `NaturalKeyUpsert`.
     * @param  string|null  $companyFrom  Colonne dont on déduit l'atelier quand
     *                                    l'appareil ne l'envoie pas.
     */
    public function __construct(
        public string $key,
        public string $model,
        public ConflictStrategy $strategy,
        public array $fields,
        public array $parents = [],
        public array $naturalKey = [],
        public ?string $companyFrom = null,
    ) {}

    /** @return Model&Replicable */
    public function newModel(): Model
    {
        return new $this->model;
    }

    /**
     * Requête incluant les pierres tombales.
     *
     * La synchronisation lit toujours les lignes effacées : leur absence est
     * précisément ce qu'un appareil hors ligne doit apprendre.
     *
     * @return Builder<Model&Replicable>
     */
    public function query(): Builder
    {
        return $this->newModel()->newQuery()->withoutGlobalScope(SoftDeletingScope::class);
    }

    /** Colonne portant l'atelier — `companies` se désigne par sa clé primaire. */
    public function companyColumn(): string
    {
        return $this->key === 'companies' ? 'id' : 'company_id';
    }

    public function table(): string
    {
        return $this->newModel()->getTable();
    }
}
