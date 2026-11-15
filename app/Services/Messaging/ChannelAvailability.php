<?php

declare(strict_types=1);

namespace App\Services\Messaging;

final readonly class ChannelAvailability
{
    public function __construct(
        public bool $available,
        /** Motif lisible par un patron d'atelier, jamais technique. */
        public ?string $reason = null,
        public bool $simulated = false,
    ) {}

    /** @return array{available: bool, reason: string|null, simulated: bool} */
    public function toArray(): array
    {
        return ['available' => $this->available, 'reason' => $this->reason, 'simulated' => $this->simulated];
    }
}
