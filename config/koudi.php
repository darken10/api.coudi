<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Numéros de téléphone
    |--------------------------------------------------------------------------
    | Pays appliqué à un numéro saisi sans indicatif (code ISO 3166-1).
    */
    'phone_region' => env('KOUDI_PHONE_REGION', 'BF'),

    /*
    |--------------------------------------------------------------------------
    | Invitations employés (code d'activation)
    |--------------------------------------------------------------------------
    | Le patron invite par téléphone ; l'employé active son compte avec le
    | code à 6 chiffres reçu par SMS ou WhatsApp, puis choisit son mot de passe.
    */
    'invitation' => [
        // Durée de validité du code, en heures.
        'code_ttl_hours' => (int) env('KOUDI_INVITATION_CODE_TTL_HOURS', 24),
        // Saisies erronées tolérées avant blocage du code.
        'max_attempts' => (int) env('KOUDI_INVITATION_MAX_ATTEMPTS', 5),
        // Délai minimal entre deux envois pour un même employé.
        'resend_seconds' => (int) env('KOUDI_INVITATION_RESEND_SECONDS', 60),
        // Nombre maximal d'envois par employé sur 24 heures.
        'max_sends_per_day' => (int) env('KOUDI_INVITATION_MAX_SENDS_PER_DAY', 5),
        // Essais de vérification par numéro sur 15 minutes, toutes invitations confondues.
        'verify_per_phone' => (int) env('KOUDI_INVITATION_VERIFY_PER_PHONE', 5),
        // Durée laissée pour choisir le mot de passe après validation du code.
        'ticket_ttl_minutes' => (int) env('KOUDI_INVITATION_TICKET_TTL_MINUTES', 30),
    ],

    /*
    | Schéma d'application mobile (deep link).
    */
    'mobile_scheme' => env('KOUDI_MOBILE_SCHEME', 'net.liptra.coudi'),

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
