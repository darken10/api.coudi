<?php

declare(strict_types=1);

namespace App\Sync;

use App\Models\Company;
use App\Models\Device;
use App\Models\Order;
use App\Models\SyncOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Montée appareil → serveur.
 *
 * Le lot entier est appliqué dans une seule transaction : le bloc de révisions
 * et les lignes qu'il numérote deviennent visibles au même instant. C'est ce
 * qui rend le curseur du `pull` sûr — un numéro visible dans le compteur
 * correspond toujours à une ligne déjà lisible.
 */
final class SyncPusher
{
    /** Tolérance sur l'horloge de l'appareil, en minutes. */
    private const CLOCK_SKEW = 5;

    /** Entité porteuse, par type de propriétaire d'un média. */
    private const MEDIA_OWNERS = [
        'order' => 'orders',
        'model' => 'design_models',
        'note' => 'order_notes',
    ];

    /**
     * @param  list<array<string, mixed>>  $ops
     * @return list<array<string, mixed>>
     */
    public function push(Company $company, Device $device, array $ops): array
    {
        $ops = $this->inDependencyOrder($ops);

        return DB::transaction(function () use ($company, $device, $ops): array {
            $replays = SyncOperation::query()
                ->where('device_id', $device->getKey())
                ->whereIn('operation_id', array_column($ops, 'op_id'))
                ->get()
                ->keyBy('operation_id');

            // Sur-réserver est sans conséquence : un trou dans la suite des
            // révisions ne fait sauter aucune ligne, il fait juste avancer le
            // curseur un peu plus vite.
            $revision = RevisionSequence::allocate($company->getKey(), max(1, count($ops)));

            $results = [];

            foreach ($ops as $op) {
                $opId = (string) ($op['op_id'] ?? '');

                if ($replays->has($opId)) {
                    $stored = $replays->get($opId);
                    $results[] = ['op_id' => $opId, 'replayed' => true] + (array) $stored->result;

                    continue;
                }

                $result = $this->applyOne($company, $device, $op, $revision);

                if ($result['status'] !== PushOutcome::Deferred->value) {
                    $revision++;
                    $this->remember($company, $device, $op, $result);
                }

                $results[] = ['op_id' => $opId] + $result;
            }

            $device->syncStateFor($company)->update(['last_pushed_at' => now()]);
            $device->forceFill(['last_seen_at' => now(), 'last_sync_at' => now()])->save();

            return $results;
        });
    }

    /**
     * Ordonne le lot parent avant enfant.
     *
     * Un client créé hors ligne, sa commande et son acompte arrivent dans le
     * même envoi : sans ce tri, deux lignes sur trois repartiraient en attente
     * de leur parent à chaque cycle.
     *
     * @param  list<array<string, mixed>>  $ops
     * @return list<array<string, mixed>>
     */
    private function inDependencyOrder(array $ops): array
    {
        $rank = array_flip(SyncRegistry::keys());

        $indexed = array_map(
            static fn (int $i, array $op): array => ['i' => $i, 'op' => $op],
            array_keys($ops),
            $ops,
        );

        usort($indexed, static function (array $a, array $b) use ($rank): int {
            $ra = $rank[$a['op']['entity'] ?? ''] ?? PHP_INT_MAX;
            $rb = $rank[$b['op']['entity'] ?? ''] ?? PHP_INT_MAX;

            // À entité égale, l'ordre d'émission de l'appareil fait foi : c'est
            // l'ordre chronologique de ses écritures.
            return $ra <=> $rb ?: $a['i'] <=> $b['i'];
        });

        return array_column($indexed, 'op');
    }

    /** @return array<string, mixed> */
    private function applyOne(Company $company, Device $device, array $op, int $revision): array
    {
        $key = (string) ($op['entity'] ?? '');

        if (! SyncRegistry::has($key)) {
            return $this->rejected("Entité inconnue : {$key}");
        }

        $entity = SyncRegistry::get($key);
        $id = (string) ($op['id'] ?? '');

        if ($id === '') {
            return $this->rejected('Identifiant manquant.');
        }

        // Le profil de l'atelier ne se pousse que sur l'atelier courant : un
        // appareil ne renomme pas l'atelier du voisin.
        if ($key === 'companies' && $id !== $company->getKey()) {
            return $this->rejected("Cet atelier n'est pas celui de la session.");
        }

        $data = (array) ($op['data'] ?? []);

        if (($op['op'] ?? 'upsert') === 'delete') {
            return $this->applyDelete($company, $device, $entity, $id, $revision);
        }

        if (($pending = $this->missingParent($company, $entity, $data)) !== null) {
            return [
                'status' => PushOutcome::Deferred->value,
                'reason' => "Parent absent : {$pending['entity']} {$pending['id']}",
                'missing' => $pending,
            ];
        }

        try {
            return match ($entity->strategy) {
                ConflictStrategy::AppendOnly => $this->applyAppendOnly($company, $device, $entity, $id, $data, $revision),
                ConflictStrategy::NaturalKeyUpsert => $this->applyNaturalKey($company, $device, $entity, $id, $data, $op, $revision),
                ConflictStrategy::LastWriteWins => $this->applyLastWriteWins($company, $device, $entity, $id, $data, $op, $revision),
            };
        } catch (Throwable $e) {
            return $this->rejected($e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function applyDelete(Company $company, Device $device, SyncEntity $entity, string $id, int $revision): array
    {
        $model = $this->find($company, $entity, $id);

        // Rien à effacer : l'appareil a peut-être déjà réussi ce push avant de
        // perdre la réponse. Le résultat doit rester le même.
        if ($model === null) {
            return ['status' => PushOutcome::Applied->value, 'id' => $id, 'revision' => $revision];
        }

        $model->markDeleted($device->getKey(), $revision);

        return ['status' => PushOutcome::Applied->value, 'id' => $id, 'revision' => $revision];
    }

    /** @return array<string, mixed> */
    private function applyAppendOnly(
        Company $company,
        Device $device,
        SyncEntity $entity,
        string $id,
        array $data,
        int $revision,
    ): array {
        $existing = $this->find($company, $entity, $id);

        // Un encaissement déjà enregistré ne se rejoue pas : on rend le même
        // résultat plutôt que d'ajouter un second paiement.
        if ($existing !== null) {
            return [
                'status' => PushOutcome::Applied->value,
                'id' => $id,
                'revision' => (int) $existing->getAttribute('revision'),
                'noop' => true,
            ];
        }

        $model = $this->fill($entity->newModel(), $entity, $data, $company, $device, $revision);
        $model->forceFill(['id' => $id])->save();

        return ['status' => PushOutcome::Applied->value, 'id' => $id, 'revision' => $revision];
    }

    /** @return array<string, mixed> */
    private function applyNaturalKey(
        Company $company,
        Device $device,
        SyncEntity $entity,
        string $id,
        array $data,
        array $op,
        int $revision,
    ): array {
        $model = $this->find($company, $entity, $id);
        $adopted = null;

        if ($model === null) {
            $model = $this->findByNaturalKey($company, $entity, $data);

            // Même mesure relevée hors ligne sur deux téléphones : une seule
            // ligne est possible, le serveur impose la sienne et l'appareil
            // réécrit son identifiant local.
            if ($model !== null) {
                $adopted = $model->getKey();
            }
        }

        if ($model === null) {
            $model = $entity->newModel();
            $model->forceFill(['id' => $id]);
        }

        $this->fill($model, $entity, $data, $company, $device, $revision, $op)->save();

        $result = ['status' => PushOutcome::Applied->value, 'id' => $model->getKey(), 'revision' => $revision];

        if ($adopted !== null) {
            $result['canonical_id'] = $adopted;
            $result['replaces'] = $id;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function applyLastWriteWins(
        Company $company,
        Device $device,
        SyncEntity $entity,
        string $id,
        array $data,
        array $op,
        int $revision,
    ): array {
        $model = $this->find($company, $entity, $id);
        $clientAt = $this->clientTimestamp($op);

        if ($model !== null) {
            $serverAt = $model->getAttribute('updated_at');

            // Le serveur détient plus récent : on ne l'écrase pas, on renvoie
            // sa version pour que l'appareil s'aligne.
            if ($serverAt !== null && $clientAt !== null && $serverAt->gt($clientAt)) {
                return [
                    'status' => PushOutcome::Conflict->value,
                    'id' => $id,
                    'revision' => (int) $model->getAttribute('revision'),
                    'reason' => 'Version serveur plus récente.',
                    'server' => $this->serverSnapshot($entity, $model),
                ];
            }
        }

        if ($model === null) {
            $model = $entity->newModel();
            $model->forceFill(['id' => $id]);
        }

        $corrections = [];

        if ($entity->key === 'orders' && isset($data['order_code'])) {
            $free = Order::availableCode($company->getKey(), (string) $data['order_code'], $id);

            // Deux appareils hors ligne génèrent fatalement le même code. On ne
            // rejette pas pour si peu : on décline le code et on dit lequel a
            // été retenu.
            if ($free !== $data['order_code']) {
                $corrections['order_code'] = $free;
                $data['order_code'] = $free;
            }
        }

        $this->fill($model, $entity, $data, $company, $device, $revision, $op)->save();

        $result = ['status' => PushOutcome::Applied->value, 'id' => $model->getKey(), 'revision' => $revision];

        if ($corrections !== []) {
            $result['changes'] = $corrections;
        }

        return $result;
    }

    /**
     * Reporte l'opération tant qu'un parent manque.
     *
     * @return array{entity: string, column: string, id: string}|null
     */
    private function missingParent(Company $company, SyncEntity $entity, array $data): ?array
    {
        $checks = $entity->parents;

        if ($entity->key === 'media_assets' && isset($data['owner_type'], $data['owner_id'])) {
            $owner = self::MEDIA_OWNERS[$data['owner_type']] ?? null;

            if ($owner !== null) {
                $checks = ['owner_id' => $owner];
            }
        }

        foreach ($checks as $column => $parentKey) {
            $value = $data[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $parent = SyncRegistry::get($parentKey);

            $exists = $parent->newModel()->newQuery()
                ->withTrashed()
                ->whereKey($value)
                ->where('company_id', $company->getKey())
                ->exists();

            if (! $exists) {
                return ['entity' => $parentKey, 'column' => $column, 'id' => (string) $value];
            }
        }

        return null;
    }

    private function find(Company $company, SyncEntity $entity, string $id): ?Model
    {
        return $entity->newModel()->newQuery()
            ->withTrashed()
            ->whereKey($id)
            ->where($entity->key === 'companies' ? 'id' : 'company_id', $company->getKey())
            ->first();
    }

    private function findByNaturalKey(Company $company, SyncEntity $entity, array $data): ?Model
    {
        if ($entity->naturalKey === []) {
            return null;
        }

        $query = $entity->newModel()->newQuery()
            ->withTrashed()
            ->where('company_id', $company->getKey());

        foreach ($entity->naturalKey as $column) {
            $value = $column === 'company_id' ? $company->getKey() : ($data[$column] ?? null);

            if ($value === null) {
                return null;
            }

            $query->where($column, $value);
        }

        return $query->first();
    }

    /** Remplit le modèle sans jamais laisser l'appareil choisir son atelier. */
    private function fill(
        Model $model,
        SyncEntity $entity,
        array $data,
        Company $company,
        Device $device,
        int $revision,
        array $op = [],
    ): Model {
        $payload = array_intersect_key($data, array_flip($entity->fields));

        $model->fill($payload);

        $model->forceFill([
            'company_id' => $company->getKey(),
            'last_device_id' => $device->getKey(),
            'revision' => $revision,
            'deleted_at' => null,
        ]);

        // Écrire l'horodatage de l'appareil garde l'arbitrage comparable d'un
        // téléphone à l'autre ; le plafonner à maintenant empêche une horloge
        // déréglée de gagner tous les conflits à venir.
        if (($clientAt = $this->clientTimestamp($op)) !== null) {
            $model->forceFill(['updated_at' => $clientAt]);
        }

        if ($entity->key === 'companies') {
            $model->forceFill(['company_id' => $company->getKey()]);
        }

        return $model;
    }

    private function clientTimestamp(array $op): ?Carbon
    {
        $raw = $op['updated_at'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $at = Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }

        $ceiling = now()->addMinutes(self::CLOCK_SKEW);

        return $at->gt($ceiling) ? $ceiling : $at;
    }

    /** @return array<string, mixed> */
    private function serverSnapshot(SyncEntity $entity, Model $model): array
    {
        $data = [];

        foreach ($entity->fields as $field) {
            $value = $model->getAttribute($field);
            $data[$field] = $value instanceof \DateTimeInterface
                ? $value->format(\DateTimeInterface::ATOM)
                : $value;
        }

        $data['company_id'] = $model->getAttribute('company_id');
        $data['updated_at'] = $model->getAttribute('updated_at')?->toIso8601String();

        return $data;
    }

    /** @return array<string, mixed> */
    private function rejected(string $reason): array
    {
        return ['status' => PushOutcome::Rejected->value, 'reason' => $reason];
    }

    private function remember(Company $company, Device $device, array $op, array $result): void
    {
        SyncOperation::query()->create([
            'device_id' => $device->getKey(),
            'company_id' => $company->getKey(),
            'operation_id' => (string) ($op['op_id'] ?? ''),
            'entity' => (string) ($op['entity'] ?? ''),
            'op' => (string) ($op['op'] ?? 'upsert'),
            'status' => $result['status'],
            'result' => $result,
        ]);
    }
}
