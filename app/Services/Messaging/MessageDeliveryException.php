<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use RuntimeException;

/**
 * Échec d'envoi. Le message de l'exception sert au journal technique ; le
 * code `reason` est ce qu'on stocke et ce que l'interface traduit.
 */
final class MessageDeliveryException extends RuntimeException
{
    public const REASON_UNAVAILABLE = 'channel_unavailable';

    public const REASON_REJECTED = 'provider_rejected';

    public const REASON_UNREACHABLE = 'provider_unreachable';

    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
