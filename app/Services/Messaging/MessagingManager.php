<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use InvalidArgumentException;

/** Sélection du fournisseur par canal choisi par le patron. */
final class MessagingManager
{
    public const CHANNELS = ['sms', 'whatsapp'];

    public function __construct(
        private readonly SmsProvider $sms,
        private readonly WhatsAppProvider $whatsapp,
    ) {}

    public function for(string $channel): MessageProvider
    {
        return match ($channel) {
            'sms' => $this->sms,
            'whatsapp' => $this->whatsapp,
            default => throw new InvalidArgumentException(sprintf('Canal inconnu : %s', $channel)),
        };
    }

    /** @return array<string, array{available: bool, reason: string|null, simulated: bool}> */
    public function availability(): array
    {
        $out = [];
        foreach (self::CHANNELS as $channel) {
            $out[$channel] = $this->for($channel)->availability()->toArray();
        }

        return $out;
    }
}
