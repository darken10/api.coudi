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
    public function pull(Company $company, int $since, array $entities = [], int $limit = self::DEFAULT_LIMIT): array
    {
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

            $found = $entity->query()
                ->where($entity->companyColumn(), $companyId)
                ->where('revision', '>', $since)
                ->where('revision', '<=', $ceiling)
                ->orderBy('revision')
                ->limit($limit + 1)
                ->get();

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

        return [
            'changes' => $changes,
            'cursor' => $cursor,
            'server_revision' => $ceiling,
            'has_more' => $cursor < $ceiling,
            'counts' => $counts,
        ];
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
