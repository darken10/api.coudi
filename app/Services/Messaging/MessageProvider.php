<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * Envoi d'un message texte à un numéro de téléphone.
 *
 * Contrat volontairement étroit : le reste de l'application ne connaît pas la
 * passerelle. Un envoi qui n'a pas été accepté par le fournisseur lève une
 * exception — il n'existe aucun chemin qui renvoie un faux succès.
 */
interface MessageProvider
{
    /** Canal servi : sms | whatsapp. */
    public function channel(): string;

    /** Le canal peut-il réellement envoyer un message maintenant ? */
    public function availability(): ChannelAvailability;

    /** @throws MessageDeliveryException si le fournisseur refuse ou n'est pas configuré */
    public function send(string $to, string $message): SentMessage;
}
