<?php

declare(strict_types=1);

namespace App\Services\Otp;

/**
 * Contrat d'envoi d'un code à usage unique.
 *
 * L'implémentation est choisie par configuration : le reste de l'application
 * ne connaît ni SMTP, ni passerelle SMS, ni API WhatsApp.
 */
interface OtpProvider
{
    /** Canal servi : email | sms | whatsapp. */
    public function channel(): string;

    /**
     * Envoie le code. Lève une exception si le fournisseur n'est pas
     * configuré ou si la passerelle échoue : l'appelant décide s'il doit le
     * signaler (sans jamais révéler l'existence du compte à l'utilisateur).
     */
    public function send(string $identifier, string $code): void;
}
