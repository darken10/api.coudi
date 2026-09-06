<?php

declare(strict_types=1);

namespace App\Models\Contracts;

/**
 * Ce que le moteur de synchronisation attend d'un modèle répliqué.
 *
 * L'interface existe pour que le moteur, qui manipule des entités décidées à
 * l'exécution par le registre, reste typé : sans elle il ne voit que des
 * `Model` anonymes et l'analyse statique ne peut plus rien garantir.
 *
 * @see \App\Models\Concerns\Syncable pour l'implémentation partagée.
 */
interface Replicable
{
    /** Le compteur de l'atelier est-il déjà en place ? */
    public function shouldAllocateRevision(): bool;

    /** Atelier propriétaire de la ligne. */
    public function syncCompanyId(): string;

    /** Pose la pierre tombale, en prélevant une révision au passage. */
    public function markDeleted(?string $deviceId = null, ?int $revision = null): void;
}
