<?php

declare(strict_types=1);

namespace App\Services\Employee;

/**
 * Code d'activation à 6 chiffres.
 *
 * Tiré par `random_int` (générateur cryptographique). Le hash est un HMAC
 * lié à l'identifiant de l'invitation et à la clé applicative : un code ne
 * vérifie que son invitation, et une copie de la base sans la clé ne permet
 * pas de retrouver les codes en essayant le million de combinaisons.
 */
final class InvitationCode
{
    public static function generate(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    public static function hash(string $invitationId, string $code): string
    {
        return hash_hmac('sha256', $invitationId.'|'.$code, config()->string('app.key'));
    }

    public static function matches(string $invitationId, string $code, string $hash): bool
    {
        return hash_equals($hash, self::hash($invitationId, $code));
    }
}
