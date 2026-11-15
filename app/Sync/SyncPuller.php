<?php

declare(strict_types=1);

namespace App\Sync;

use App\Models\Company;
use App\Models\Contracts\Replicable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Descente serveur → appareil.
 *
 * L'appareil dit « j'en suis à la révision N », le serveur renvoie tout ce qui
 * est passé au-delà, effacements compris, et le nouveau curseur.
 */
final class SyncPuller
{
    public const DEFAULT_LIMIT = 500;

    public const MAX_LIMIT = 2000;

    /**
     * @param  list<string>  $entities  Vide = toutes les entités du registre.
     * @return array{
     *     changes: array<string, list<array<string, mixed>>>,
     *     cursor: int,
     *     server_revision: int,
     *     has_more: bool,
     *     counts: array<string, int>
     * }
     */
    public function pull(
        Company $company,
        int $since,
        array $entities = [],
        int $limit = self::DEFAULT_LIMIT,
        ?SyncScope $scope = null,
    ): array {
        $scope ??= new SyncScope($company, null, false);
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $companyId = $company->syncCompanyId();

        /*
         * Le plafond est lu AVANT les requêtes, et il est sûr parce que tout
         * push commite son bloc de révisions et ses lignes d'un seul tenant :
         * un numéro visible dans le compteur correspond donc toujours à une
         * ligne déjà lisible. Sans cette règle, le curseur pourrait sauter
         * par-dessus une écriture encore en vol, définitivement perdue.
         */
        $ceiling = RevisionSequence::current($companyId);

        $keys = $entities === [] ? SyncRegistry::keys() : SyncRegistry::ordered($entities);

        /** @var array<string, Collection<int, Model&Replicable>> $rows */
        $rows = [];

        /** @var array<string, int> $truncatedAt */
        $truncatedAt = [];

        foreach ($keys as $key) {
            $entity = SyncRegistry::get($key);

            $query = $entity->query()
                ->where($entity->companyColumn(), $companyId)
                ->where('revision', '>', $since)
                ->where('revision', '<=', $ceiling)
                ->orderBy('revision')
                ->limit($limit + 1);

            // Le cloisonnement employé s'ajoute au cloisonnement atelier.
            $scope->apply($key, $query);

            $found = $query->get();

            if ($found->count() > $limit) {
                $found = $found->take($limit);
                $last = $found->last();
                $truncatedAt[$key] = $last === null ? $ceiling : $this->intOf($last, 'revision');
            }

            $rows[$key] = $found;
        }

        /*
         * Une entité tronquée impose sa borne à toutes les autres : avancer le
         * curseur au-delà laisserait derrière soi le reste de sa page.
         */
        $cursor = $truncatedAt === [] ? $ceiling : min($truncatedAt);

        /** @var array<string, list<array<string, mixed>>> $changes */
        $changes = [];

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($rows as $key => $collection) {
            $entity = SyncRegistry::get($key);

            $kept = [];

            foreach ($collection as $model) {
                if ($this->intOf($model, 'revision') <= $cursor) {
                    $kept[] = $this->serialize($entity, $model);
                }
            }

            if ($kept !== []) {
                $changes[$key] = $kept;
                $counts[$key] = count($kept);
            }
        }

        $changes = $this->withMissingParents($changes, $counts, $companyId, $cursor, $scope);

        return [
            'changes' => $changes,
            'cursor' => $cursor,
            'server_revision' => $ceiling,
            'has_more' => $cursor < $ceiling,
            'counts' => $counts,
        ];
    }

    /**
     * Rend chaque page autonome : tout parent référencé par une ligne de la
     * page y figure aussi.
     *
     * Les lignes partent par révision croissante. Un parent modifié après ses
     * enfants (un client corrigé après sa prise de mesures) porte une révision
     * plus haute : sur un gros atelier, il tombe dans une page suivante. Le
     * téléphone, qui applique chaque page d'un seul bloc sous contrainte de
     * clé étrangère, rejetait alors la page entière — atelier compris — et la
     * restauration après réinstallation laissait une liste vide.
     *
     * Le parent est donc joint dès maintenant. Il repartira à sa propre
     * révision dans une page ultérieure : l'écriture côté téléphone est
     * idempotente, ce doublon est sans effet. Le cloisonnement employé
     * s'applique aussi aux parents ajoutés.
     *
     * @param  array<string, list<array<string, mixed>>>  $changes
     * @param  array<string, int>  $counts
     * @return array<string, list<array<string, mixed>>>
     */
    private function withMissingParents(array $changes, array &$counts, string $companyId, int $cursor, SyncScope $scope): array
    {
        if ($changes === []) {
            return $changes;
        }

        // Plusieurs passes : un parent ajouté peut lui-même avoir un parent
        // absent (paiement → commande → client). La profondeur réelle est 3.
        /** @var array<string, array<string, true>> $index */
        $index = [];
        foreach ($changes as $key => $rows) {
            foreach ($rows as $row) {
                $index[$key][$this->idOf($row['id'] ?? null)] = true;
            }
        }

        for ($pass = 0; $pass < 5; $pass++) {
            /** @var array<string, array<string, true>> $missing */
            $missing = [];

            foreach ($changes as $key => $rows) {
                foreach (SyncRegistry::get($key)->parents as $column => $parentKey) {
                    foreach ($rows as $row) {
                        $data = $row['data'] ?? null;
                        $value = is_array($data) ? ($data[$column] ?? null) : null;

                        if (is_string($value) && $value !== '' && ! isset($index[$parentKey][$value])) {
                            $missing[$parentKey][$value] = true;
                        }
                    }
                }
            }

            // L'atelier est le parent de toutes les lignes qui portent company_id.
            if (! isset($index['companies'][$companyId])) {
                $missing['companies'][$companyId] = true;
            }

            $added = 0;

            foreach ($missing as $parentKey => $ids) {
                $entity = SyncRegistry::get($parentKey);
                $query = $entity->query()
                    ->whereIn($entity->newModel()->getKeyName(), array_keys($ids))
                    ->where('revision', '>', $cursor)
                    ->whereNull('deleted_at');

                if ($parentKey !== 'companies') {
                    $query->where($entity->companyColumn(), $companyId);
                }

                $scope->apply($parentKey, $query);

                foreach ($query->get() as $model) {
                    $index[$parentKey][$this->idOf($model->getKey())] = true;
                    $changes[$parentKey][] = $this->serialize($entity, $model);
                    $counts[$parentKey] = ($counts[$parentKey] ?? 0) + 1;
                    $added++;
                }
            }

            if ($added === 0) {
                break;
            }
        }

        return $changes;
    }

    private function idOf(mixed $id): string
    {
        return is_scalar($id) ? (string) $id : '';
    }

    /**
     * Une ligne effacée ne transporte pas ses champs : seule compte
     * l'instruction d'effacement.
     *
     * @param  Model&Replicable  $model
     * @return array<string, mixed>
     */
    private function serialize(SyncEntity $entity, Model $model): array
    {
        $deletedAt = $model->getAttribute('deleted_at');

        $row = [
            'id' => $model->getKey(),
            'op' => $deletedAt === null ? 'upsert' : 'delete',
            'revision' => $this->intOf($model, 'revision'),
            'updated_at' => $this->scalar($model->getAttribute('updated_at')),
        ];

        if ($deletedAt !== null) {
            $row['deleted_at'] = $this->scalar($deletedAt);

            return $row;
        }

        $data = [];

        foreach ($entity->fields as $field) {
            $data[$field] = $this->scalar($model->getAttribute($field));
        }

        if ($entity->key !== 'companies') {
            $data['company_id'] = $model->getAttribute('company_id');
        }

        $row['data'] = $data;

        return $row;
    }

    /** Les dates partent en ISO 8601 ; SQLite mobile ne lit pas les objets Carbon. */
    private function scalar(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface
            ? $value->format(DateTimeInterface::ATOM)
            : $value;
    }

    private function intOf(Model $model, string $attribute): int
    {
        $value = $model->getAttribute($attribute);

        return is_numeric($value) ? (int) $value : 0;
    }
}
