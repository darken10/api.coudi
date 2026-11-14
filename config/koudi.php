<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Invitations employés
    |--------------------------------------------------------------------------
    | Durée de validité configurable, en heures. Le jeton est à usage unique.
    */
    'invitation_ttl_hours' => (int) env('KOUDI_INVITATION_TTL_HOURS', 72),

    /*
    | Schéma d'application mobile (deep link). Le lien web d'activation
    | redirige vers `{scheme}://activer?token=...`.
    */
    'mobile_scheme' => env('KOUDI_MOBILE_SCHEME', 'net.liptra.coudi'),

    /*
    | URL publique de la page d'activation (back-office web).
    */
    'activation_url' => env('KOUDI_ACTIVATION_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/activer'),

    /*
    |--------------------------------------------------------------------------
    | Réinitialisation OTP
    |--------------------------------------------------------------------------
    */
    'otp' => [
        'ttl_minutes' => (int) env('KOUDI_OTP_TTL_MINUTES', 10),
        'max_attempts' => (int) env('KOUDI_OTP_MAX_ATTEMPTS', 5),
        // Délai minimal entre deux demandes pour un même identifiant.
        'resend_seconds' => (int) env('KOUDI_OTP_RESEND_SECONDS', 60),
        // Durée du jeton de réinitialisation remis après vérification du code.
        'reset_token_ttl_minutes' => (int) env('KOUDI_OTP_RESET_TOKEN_TTL_MINUTES', 15),
        // Canal proposé par défaut à l'utilisateur.
        'default_channel' => env('KOUDI_OTP_DEFAULT_CHANNEL', 'email'),
    ],
];
