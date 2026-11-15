<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * Accusé d'acceptation du fournisseur.
 *
 * « Accepté » ne veut pas dire « livré » : la livraison n'est connue que si le
 * fournisseur la signale plus tard. `simulated` vaut true uniquement en
 * développement local, quand le message est écrit dans le journal.
 */
final readonly class SentMessage
{
    public function __construct(
        public ?string $providerMessageId = null,
        public bool $simulated = false,
    ) {}
}
