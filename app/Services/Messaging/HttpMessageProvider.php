<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Passerelle HTTP générique, commune au SMS et à WhatsApp.
 *
 * Contrat attendu : POST sur l'URL configurée, jeton Bearer, corps JSON
 * `{ to, from, message, channel }`. Une réponse 2xx vaut acceptation ; un
 * identifiant `id` ou `message_id` dans la réponse est conservé pour le suivi.
 *
 * Drivers :
 * - `http`  : envoi réel, disponible si l'URL et le jeton sont renseignés ;
 * - `log`   : écrit le message dans le journal, en développement local
 *             uniquement — ailleurs le canal est déclaré indisponible ;
 * - `array` : tests automatisés uniquement (MessageOutbox).
 */
abstract class HttpMessageProvider implements MessageProvider
{
    public function __construct(private readonly MessageOutbox $outbox) {}

    abstract public function channel(): string;

    abstract protected function label(): string;

    public function availability(): ChannelAvailability
    {
        $driver = $this->driver();

        if ($driver === 'http') {
            return $this->url() !== '' && $this->token() !== ''
                ? new ChannelAvailability(true)
                : new ChannelAvailability(false, sprintf('L’envoi par %s n’est pas encore configuré sur le serveur.', $this->label()));
        }

        if ($driver === 'array' && app()->environment('testing')) {
            return new ChannelAvailability(true);
        }

        if ($driver === 'log' && app()->environment('local', 'testing')) {
            return new ChannelAvailability(true, 'Mode développement : le message est écrit dans le journal du serveur.', simulated: true);
        }

        return new ChannelAvailability(false, sprintf('L’envoi par %s n’est pas disponible pour le moment.', $this->label()));
    }

    public function send(string $to, string $message): SentMessage
    {
        $availability = $this->availability();

        if (! $availability->available) {
            throw new MessageDeliveryException(
                sprintf('Canal %s indisponible (driver=%s).', $this->channel(), $this->driver()),
                MessageDeliveryException::REASON_UNAVAILABLE,
            );
        }

        return match ($this->driver()) {
            'array' => $this->sendToOutbox($to, $message),
            'log' => $this->sendToLog($to, $message),
            default => $this->sendOverHttp($to, $message),
        };
    }

    private function sendOverHttp(string $to, string $message): SentMessage
    {
        try {
            $response = Http::withToken($this->token())
                ->acceptJson()
                ->timeout(10)
                ->post($this->url(), [
                    'to' => $to,
                    'from' => config(sprintf('otp.providers.%s.from', $this->channel())),
                    'message' => $message,
                    'channel' => $this->channel(),
                ]);
        } catch (ConnectionException $exception) {
            throw new MessageDeliveryException(
                sprintf('Passerelle %s injoignable.', $this->channel()),
                MessageDeliveryException::REASON_UNREACHABLE,
            );
        }

        if ($response->failed()) {
            // Ni le destinataire complet ni le contenu ne vont au journal.
            throw new MessageDeliveryException(
                sprintf('Envoi %s refusé (HTTP %d).', $this->channel(), $response->status()),
                MessageDeliveryException::REASON_REJECTED,
            );
        }

        $id = $response->json('id') ?? $response->json('message_id') ?? $response->json('data.id');

        return new SentMessage(is_scalar($id) ? (string) $id : null);
    }

    private function sendToOutbox(string $to, string $message): SentMessage
    {
        $this->outbox->push($this->channel(), $to, $message);

        return new SentMessage('array-'.bin2hex(random_bytes(4)));
    }

    private function sendToLog(string $to, string $message): SentMessage
    {
        // Développement local uniquement (voir availability()).
        Log::info(sprintf('[%s simulé] %s', $this->channel(), $to), ['message' => $message]);

        return new SentMessage(null, simulated: true);
    }

    private function driver(): string
    {
        return $this->setting('driver', 'log');
    }

    private function url(): string
    {
        return $this->setting('url');
    }

    private function token(): string
    {
        return $this->setting('token');
    }

    private function setting(string $key, string $default = ''): string
    {
        $value = config(sprintf('otp.providers.%s.%s', $this->channel(), $key));

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
