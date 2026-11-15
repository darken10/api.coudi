<?php

declare(strict_types=1);

namespace App\Services\Messaging;

final class HttpWhatsAppProvider extends HttpMessageProvider implements WhatsAppProvider
{
    public function channel(): string
    {
        return 'whatsapp';
    }

    protected function label(): string
    {
        return 'WhatsApp';
    }
}
