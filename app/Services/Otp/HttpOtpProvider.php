<?php

declare(strict_types=1);

namespace App\Services\Otp;

use App\Services\Messaging\MessageDeliveryException;
use App\Services\Messaging\MessagingManager;
use Illuminate\Support\Facades\Log;

/**
 * Codes de réinitialisation par SMS et WhatsApp.
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

        // Le code n'est écrit dans le journal qu'en développement local :
        // en production, un driver `log` oublié ne doit pas livrer les codes.
        if ($driver === 'log' && app()->environment('local')) {
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

        // Envoi réel : même passerelle que les invitations employés.
        try {
            app(MessagingManager::class)->for($this->channel)->send($identifier, sprintf(
                'Votre code COUDI est %s. Il expire dans %d minutes. Ne le partagez avec personne.',
                $code,
                config()->integer('koudi.otp.ttl_minutes', 10),
            ));
        } catch (MessageDeliveryException $exception) {
            throw new OtpDeliveryException($exception->getMessage());
        }
    }
}
