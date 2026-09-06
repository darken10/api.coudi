<?php

declare(strict_types=1);

namespace App\Sync;

use Illuminate\Database\Eloquent\Model;

/**
 * Description d'une entité répliquée : ce qui voyage, d'où vient son atelier,
 * de quoi elle dépend, et comment on arbitre ses conflits.
 */
final readonly class SyncEntity
{
    /**
     * @param  string  $key  Nom transporté dans le protocole (`clients`, `orders`…).
     * @param  class-string<Model>  $model
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

    public function newModel(): Model
    {
        /** @var Model $model */
        $model = new $this->model;

        return $model;
    }

    public function table(): string
    {
        return $this->newModel()->getTable();
    }
}
