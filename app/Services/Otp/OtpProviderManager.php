<?php

declare(strict_types=1);

namespace App\Services\Otp;

use InvalidArgumentException;

final class OtpProviderManager
{
    /** @var array<string, OtpProvider> */
    private array $resolved = [];

    public function __construct(private readonly OtpCodeRegistry $codes) {}

    public function forChannel(string $channel): OtpProvider
    {
        return $this->resolved[$channel] ??= match ($channel) {
            'email' => new EmailOtpProvider($this->codes),
            'sms', 'whatsapp' => new HttpOtpProvider($channel, $this->codes),
            default => throw new InvalidArgumentException(sprintf('Canal OTP inconnu : %s', $channel)),
        };
    }
}
