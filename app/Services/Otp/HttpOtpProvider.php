<?php

declare(strict_types=1);

namespace App\Services\Otp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Passerelle HTTP générique pour SMS et WhatsApp.
 *
 * Le contrat attendu est minimal et documenté : POST sur l'URL configurée avec
 * `{ to, message, from }` et un jeton Bearer. Il ne s'agit donc pas d'un
 * bouchon : brancher un vrai fournisseur consiste uniquement à renseigner
 * l'URL et le jeton dans l'environnement.
 */
final class HttpOtpProvider implements OtpProvider
{
    public function __construct(
        private readonly string $channel,
        private readonly OtpCodeRegistry $codes,
    ) {}

    public function channel(): string
    {
        return $this->channel;
    }

    public function send(string $identifier, string $code): void
    {
        $config = config(sprintf('otp.providers.%s', $this->channel), []);
        $driver = (string) ($config['driver'] ?? 'log');

        if ($driver === 'log') {
            Log::info(sprintf('Code OTP %s émis', $this->channel), [
                'identifier' => $identifier,
                'code' => $code,
            ]);

            return;
        }

        if ($driver === 'array') {
            $this->codes->put($this->channel, $identifier, $code);

            return;
        }

        $url = (string) ($config['url'] ?? '');
        $token = (string) ($config['token'] ?? '');

        if ($driver !== 'http' || $url === '' || $token === '') {
            throw new OtpDeliveryException(sprintf(
                'Fournisseur %s non configuré (driver=%s, url=%s).',
                $this->channel,
                $driver,
                $url === '' ? 'vide' : 'définie',
            ));
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(10)
            ->post($url, [
                'to' => $identifier,
                'from' => $config['from'] ?? null,
                'message' => sprintf(
                    'Votre code Koudi est %s. Il expire dans %d minutes.',
                    $code,
                    (int) config('koudi.otp.ttl_minutes', 10),
                ),
            ]);

        if ($response->failed()) {
            // On ne journalise jamais le code ni le destinataire complet.
            throw new OtpDeliveryException(sprintf(
                'Échec de l’envoi %s (HTTP %d).',
                $this->channel,
                $response->status(),
            ));
        }
    }
}
