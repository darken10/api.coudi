<?php

declare(strict_types=1);

namespace App\Services\Otp;

/**
 * Dernier code émis par canal et identifiant.
 *
 * Utilisé uniquement par le driver `array` (tests) : il permet de vérifier le
 * parcours complet sans dépendre d'une boîte mail ni d'une passerelle SMS.
 */
final class OtpCodeRegistry
{
    /** @var array<string, string> */
    private array $codes = [];

    public function put(string $channel, string $identifier, string $code): void
    {
        $this->codes[$channel.'|'.$identifier] = $code;
    }

    public function get(string $channel, string $identifier): ?string
    {
        return $this->codes[$channel.'|'.$identifier] ?? null;
    }

    public function flush(): void
    {
        $this->codes = [];
    }
}
