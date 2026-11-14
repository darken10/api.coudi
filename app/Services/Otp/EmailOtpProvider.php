<?php

declare(strict_types=1);

namespace App\Services\Otp;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class EmailOtpProvider implements OtpProvider
{
    public function __construct(private readonly OtpCodeRegistry $codes) {}

    public function channel(): string
    {
        return 'email';
    }

    public function send(string $identifier, string $code): void
    {
        $driver = (string) config('otp.providers.email.driver', 'log');

        if ($driver === 'log') {
            Log::info('Code OTP email émis', [
                'identifier' => $identifier,
                'code' => $code,
            ]);

            return;
        }

        if ($driver === 'array') {
            $this->codes->put($this->channel(), $identifier, $code);

            return;
        }

        if ($driver !== 'mail') {
            throw new OtpDeliveryException(sprintf('Fournisseur email inconnu : %s', $driver));
        }

        $ttl = (int) config('koudi.otp.ttl_minutes', 10);

        Mail::raw(
            sprintf(
                "Votre code Koudi est %s.\nIl expire dans %d minutes.\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message.",
                $code,
                $ttl,
            ),
            static function ($message) use ($identifier): void {
                $message->to($identifier)
                    ->subject((string) config('otp.providers.email.subject'));
            },
        );
    }
}
