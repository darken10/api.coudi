<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Admin Console Session (hours)
    |--------------------------------------------------------------------------
    | Plus courte que la session mobile : la console expose toutes les bases.
    */
    'admin_expiry_hours' => (int) env('ADMIN_SESSION_EXPIRY_HOURS', 12),

    /*
    |--------------------------------------------------------------------------
    | Access Token Expiry (days)
    |--------------------------------------------------------------------------
    | AUTH_ACCESS_TOKEN_EXPIRY_DAYS in .env
    | Null = never expires (Sanctum default)
    */
    'access_expiry_days' => env('AUTH_ACCESS_TOKEN_EXPIRY_DAYS') !== null
        ? (int) env('AUTH_ACCESS_TOKEN_EXPIRY_DAYS')
        : null,

    /*
    |--------------------------------------------------------------------------
    | Refresh Token Expiry (days)
    |--------------------------------------------------------------------------
    | AUTH_REFRESH_TOKEN_EXPIRY_DAYS in .env
    */
    'refresh_expiry_days' => (int) env('AUTH_REFRESH_TOKEN_EXPIRY_DAYS', 365),
];
