<?php

declare(strict_types=1);

namespace App\Services\Otp;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Jeton opaque remis après vérification d'un OTP.
 *
 * Il ne contient que l'identifiant du compte et une échéance, chiffrés et
 * authentifiés par la clé applicative. Aucun état en base n'est nécessaire :
 * un jeton expiré ou altéré est rejeté à la déchiffration.
 */
final class PasswordResetTicket
{
    private const PURPOSE = 'password-reset';

    public function issue(User $user): string
    {
        $ttl = (int) config('koudi.otp.reset_token_ttl_minutes', 15);

        return Crypt::encryptString((string) json_encode([
            'purpose' => self::PURPOSE,
            'user_id' => $user->id,
            'exp' => now()->addMinutes($ttl)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    public function resolve(string $token): ?User
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (($payload['purpose'] ?? null) !== self::PURPOSE
            || ! is_int($payload['exp'] ?? null)
            || $payload['exp'] < now()->getTimestamp()) {
            return null;
        }

        return User::query()->find($payload['user_id'] ?? null);
    }
}
