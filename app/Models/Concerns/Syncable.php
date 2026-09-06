<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Sync\RevisionSequence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rend une entité répliquable vers les appareils.
 *
 * Trois garanties :
 *  - identité stable : l'UUID posé par l'appareil est repris tel quel
 *    (`HasUuids` n'en génère un que si la clé arrive vide) ;
 *  - ordonnancement  : toute écriture prélève une révision sur le compteur de
 *    l'atelier, ce qui donne aux appareils un curseur de rattrapage fiable ;
 *  - effacement visible : la suppression est une pierre tombale, sans quoi un
 *    appareil hors ligne au moment de l'effacement ne l'apprendrait jamais.
 *
 * @mixin Model
 */
trait Syncable
{
    use HasUuids;
    use SoftDeletes;

    public static function bootSyncable(): void
    {
        static::saving(function (Model $model): void {
            /*
             * Le moteur de push réserve un bloc de révisions et les pose
             * lui-même : on ne prélève ici que pour les écritures isolées
             * (console web, commandes artisan, tests).
             */
            if (! $model->isDirty('revision')) {
                $model->setAttribute('revision', RevisionSequence::next($model->syncCompanyId()));
            }
        });
    }

    /** Atelier propriétaire — `Company` se désigne elle-même. */
    public function syncCompanyId(): string
    {
        return (string) $this->getAttribute('company_id');
    }

    /**
     * Pose la pierre tombale.
     *
     * `SoftDeletes::runSoftDelete()` écrit directement en base sans passer par
     * `save()` : la révision n'y serait jamais prélevée et l'effacement
     * resterait invisible aux autres appareils. On passe donc par une écriture
     * normale.
     */
    public function markDeleted(?string $deviceId = null, ?int $revision = null): void
    {
        $this->forceFill(array_filter([
            'deleted_at' => $this->freshTimestamp(),
            'last_device_id' => $deviceId,
            'revision' => $revision,
        ], static fn (mixed $v): bool => $v !== null))->save();
    }

    /** Tout ce qui a changé au-delà d'une révision, effacements compris. */
    public function scopeChangedSince(Builder $query, string $companyId, int $revision): Builder
    {
        return $query->withTrashed()
            ->where('company_id', $companyId)
            ->where('revision', '>', $revision)
            ->orderBy('revision');
    }
}
