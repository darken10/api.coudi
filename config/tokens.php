<?php

declare(strict_types=1);

return [
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
