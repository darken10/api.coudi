<?php

declare(strict_types=1);

namespace App\Services\Messaging;

final class HttpSmsProvider extends HttpMessageProvider implements SmsProvider
{
    public function channel(): string
    {
        return 'sms';
    }

    protected function label(): string
    {
        return 'SMS';
    }
}
