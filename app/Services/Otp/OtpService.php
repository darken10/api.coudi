<?php

declare(strict_types=1);

namespace App\Services\Otp;

use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Émission et vérification des codes de réinitialisation.
 *
 * Règles appliquées :
 * - 6 chiffres, jamais stockés en clair (HMAC-SHA256 avec la clé applicative) ;
 * - un seul code actif par identifiant et par canal ;
 * - durée de vie et nombre de tentatives bornés par configuration ;
 * - réponse identique que le compte existe ou non (pas d'énumération).
 */
final class OtpService
{
    public function __construct(
        private readonly OtpProviderManager $providers,
        private readonly PasswordResetTicket $tickets,
    ) {}

    public function request(string $identifier, string $channel, ?string $ip = null): void
    {
        $identifier = $this->normalize($identifier);

        if (! in_array($channel, PasswordResetOtp::CHANNELS, true) || $identifier === '') {
            return;
        }

        $user = $this->findUser($identifier);

        if ($user === null) {
            // Compte inconnu : on ne crée rien et on ne lève rien. Le
            // contrôleur renverra le même message qu'en cas de succès.
            return;
        }

        $this->invalidatePrevious($identifier, $channel);

        if ($this->isThrottled($identifier, $channel)) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = (int) config('koudi.otp.ttl_minutes', 10);

        PasswordResetOtp::query()->create([
            'user_id' => $user->id,
            'identifier' => $identifier,
            'channel' => $channel,
            'code_hash' => $this->hashCode($identifier, $code),
            'max_attempts' => (int) config('koudi.otp.max_attempts', 5),
            'expires_at' => now()->addMinutes($ttl),
            'ip' => $ip,
        ]);

        try {
            $this->providers->forChannel($channel)->send($identifier, $code);
        } catch (OtpDeliveryException $exception) {
            // L'erreur est journalisée sans le code ; l'API reste générique.
            Log::warning('Envoi OTP impossible', [
                'channel' => $channel,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Vérifie un code et retourne un jeton de réinitialisation à durée de vie
     * courte, ou null si le code est invalide/expiré/trop tenté.
     */
    public function verify(string $identifier, string $channel, string $code): ?string
    {
        $identifier = $this->normalize($identifier);

        $otp = PasswordResetOtp::query()
            ->where('identifier', $identifier)
            ->where('channel', $channel)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($otp === null) {
            return null;
        }

        if (! $otp->expires_at->isFuture() || $otp->attempts >= $otp->max_attempts) {
            return null;
        }

        if (! $this->codeMatches($otp, $identifier, $code)) {
            $otp->increment('attempts');

            return null;
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        $user = $otp->user;

        return $user === null ? null : $this->tickets->issue($user);
    }

    public function resetPassword(string $token, string $password): ?User
    {
        $user = $this->tickets->resolve($token);

        if ($user === null) {
            return null;
        }

        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password' => $password])->save();

            // Une réinitialisation ferme toutes les sessions existantes.
            $user->tokens()->delete();
            $user->refreshTokens()->delete();
        });

        return $user;
    }

    public function findUser(string $identifier): ?User
    {
        return User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->orWhere('whatsapp', $identifier)
            ->first();
    }

    private function invalidatePrevious(string $identifier, string $channel): void
    {
        PasswordResetOtp::query()
            ->where('identifier', $identifier)
            ->where('channel', $channel)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }

    private function isThrottled(string $identifier, string $channel): bool
    {
        $seconds = (int) config('koudi.otp.resend_seconds', 60);

        if ($seconds <= 0) {
            return false;
        }

        $latest = PasswordResetOtp::query()
            ->where('identifier', $identifier)
            ->where('channel', $channel)
            ->latest('id')
            ->value('created_at');

        return $latest !== null && now()->diffInSeconds($latest) < $seconds;
    }

    private function hashCode(string $identifier, string $code): string
    {
        return hash_hmac('sha256', $identifier.'|'.$code, (string) config('app.key'));
    }

    private function codeMatches(PasswordResetOtp $otp, string $identifier, string $code): bool
    {
        return hash_equals($otp->code_hash, $this->hashCode($identifier, $code));
    }

    private function normalize(string $identifier): string
    {
        $identifier = trim($identifier);

        if (Str::contains($identifier, '@')) {
            return Str::lower($identifier);
        }

        return (string) preg_replace('/[^0-9+]/', '', $identifier);
    }
}
