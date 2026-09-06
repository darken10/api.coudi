<?php

declare(strict_types=1);

namespace App\Sync;

use Illuminate\Support\Facades\DB;

/**
 * Compteur monotone par atelier — l'horloge logique de la synchronisation.
 *
 * Chaque écriture répliquée prélève un numéro sur `companies.sync_revision`.
 * Un appareil qui a vu la révision 812 demande « tout ce qui est passé au-delà
 * de 812 » : pas d'horloge murale, donc pas de dérive d'horloge entre
 * téléphones, et une pagination stable même si deux écritures partagent la même
 * seconde.
 */
final class RevisionSequence
{
    /** Prélève un numéro unique pour une écriture isolée. */
    public static function next(string $companyId): int
    {
        return self::allocate($companyId, 1);
    }

    /**
     * Réserve un bloc de `$count` numéros et renvoie le premier.
     *
     * Un push de 500 lignes ne doit pas produire 500 allers-retours sur le
     * compteur : on réserve la plage d'un coup et on la distribue en mémoire.
     */
    public static function allocate(string $companyId, int $count): int
    {
        if ($count < 1) {
            $count = 1;
        }

        return DB::transaction(function () use ($companyId, $count): int {
            $current = (int) DB::table('companies')
                ->where('id', $companyId)
                ->lockForUpdate()
                ->value('sync_revision');

            DB::table('companies')
                ->where('id', $companyId)
                ->update(['sync_revision' => $current + $count]);

            return $current + 1;
        });
    }

    /** Révision courante de l'atelier : la borne haute d'un `pull`. */
    public static function current(string $companyId): int
    {
        return (int) DB::table('companies')->where('id', $companyId)->value('sync_revision');
    }
}
