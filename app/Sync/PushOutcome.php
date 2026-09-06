<?php

declare(strict_types=1);

namespace App\Sync;

/**
 * Sort d'une opération poussée.
 *
 * `Deferred` est le seul état non terminal : il n'est pas enregistré au
 * registre d'idempotence, puisque l'appareil doit le rejouer.
 */
enum PushOutcome: string
{
    /** Écriture appliquée — éventuellement corrigée (code de commande renuméroté). */
    case Applied = 'applied';

    /** Le serveur détient une version plus récente : l'appareil doit l'adopter. */
    case Conflict = 'conflict';

    /** Parent pas encore arrivé : à rejouer au cycle suivant, sans erreur. */
    case Deferred = 'deferred';

    /** Refus définitif — entité inconnue, données invalides, atelier étranger. */
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return $this !== self::Deferred;
    }
}
