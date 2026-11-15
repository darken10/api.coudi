<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * Messages « envoyés » par le driver `array`.
 *
 * Réservé aux tests automatisés : ils relisent le message reçu par un numéro
 * sans passerelle réelle. Ce driver est refusé hors environnement de test.
 */
final class MessageOutbox
{
    /** @var array<string, list<string>> */
    private array $messages = [];

    public function push(string $channel, string $to, string $message): void
    {
        $this->messages[$channel.'|'.$to][] = $message;
    }

    public function last(string $channel, string $to): ?string
    {
        $list = $this->messages[$channel.'|'.$to] ?? [];

        return $list === [] ? null : $list[array_key_last($list)];
    }

    public function count(string $channel, string $to): int
    {
        return count($this->messages[$channel.'|'.$to] ?? []);
    }

    public function flush(): void
    {
        $this->messages = [];
    }
}
