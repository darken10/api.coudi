<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Émission de la paire jeton d'accès / jeton de rafraîchissement.
 *
 * Centralisé pour que la connexion classique et l'activation d'un compte
 * employé délivrent exactement le même format.
 */
final class TokenIssuer
{
    /** @return array<string, mixed> */
    public function issue(User $user): array
    {
        $expiresAt = $this->accessTokenExpiresAt();
        $accessToken = $user->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;
        [$refresh] = RefreshToken::issue($user->id);

        return [
            'user' => $user,
            'access_token' => $accessToken,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }

    /**
     * Voir AuthController : null signifie « sans échéance », car la colonne
     * MySQL TIMESTAMP déborde au-delà de 2038.
     */
    public function accessTokenExpiresAt(): ?Carbon
    {
        $days = config('tokens.access_expiry_days');

        return $days !== null ? now()->addDays((int) $days) : null;
    }
}
