<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Fournisseurs d'envoi OTP
    |--------------------------------------------------------------------------
    | Chaque canal possède son propre fournisseur, interchangeable. `log`
    | écrit le code dans le journal applicatif (développement et tests) ;
    | `mail` et `http` sont des intégrations réelles.
    |
    | Aucun fournisseur SMS/WhatsApp n'est supposé existant : tant que l'URL et
    | le jeton ne sont pas renseignés, l'envoi échoue proprement et l'API répond
    | de façon générique pour ne pas révéler l'existence d'un compte.
    */
    'providers' => [
        'email' => [
            'driver' => env('KOUDI_OTP_EMAIL_DRIVER', 'log'), // log | mail
            'from' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
            'from_name' => env('MAIL_FROM_NAME', 'Koudi'),
            'subject' => 'Votre code de réinitialisation Koudi',
        ],

        'sms' => [
            'driver' => env('KOUDI_OTP_SMS_DRIVER', 'log'), // log | http
            'url' => env('KOUDI_SMS_URL'),
            'token' => env('KOUDI_SMS_TOKEN'),
            'from' => env('KOUDI_SMS_FROM'),
        ],

        'whatsapp' => [
            'driver' => env('KOUDI_OTP_WHATSAPP_DRIVER', 'log'), // log | http
            'url' => env('KOUDI_WHATSAPP_URL'),
            'token' => env('KOUDI_WHATSAPP_TOKEN'),
            'from' => env('KOUDI_WHATSAPP_FROM'),
        ],
    ],
];
