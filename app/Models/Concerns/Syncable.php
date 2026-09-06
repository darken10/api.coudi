<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Sync\RevisionSequence;
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
 * Une écriture isolée doit s'entourer d'une transaction : le prélèvement de la
 * révision et la ligne qu'elle numérote doivent devenir visibles ensemble, sous
 * peine qu'un `pull` concurrent avance son curseur par-dessus.
 *
 * @mixin Model
 */
trait Syncable
{
    use HasUuids;
    use SoftDeletes;

    public static function bootSyncable(): void
    {
        static::saving(function (self $model): void {
            /*
             * Le moteur de push réserve un bloc de révisions et les pose
             * lui-même : on ne prélève ici que pour les écritures isolées
             * (console web, commandes artisan, tests).
             */
            if (! $model->isDirty('revision') && $model->shouldAllocateRevision()) {
                $model->setAttribute('revision', RevisionSequence::next($model->syncCompanyId()));
            }
        });
    }

    /**
     * Le compteur est-il déjà en place ?
     *
     * `Company` répond non à sa propre création : le compteur vit sur la ligne
     * qu'on est en train d'insérer, il n'y a encore rien à y prélever.
     */
    public function shouldAllocateRevision(): bool
    {
        return true;
    }

    /** Atelier propriétaire — `Company` se désigne elle-même. */
    public function syncCompanyId(): string
    {
        /** @var string $companyId */
        $companyId = $this->getAttribute('company_id');

        return $companyId;
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
        $columns = ['deleted_at' => $this->freshTimestamp()];

        if ($deviceId !== null) {
            $columns['last_device_id'] = $deviceId;
        }

        if ($revision !== null) {
            $columns['revision'] = $revision;
        }

        $this->forceFill($columns)->save();
    }
}
