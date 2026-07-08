<?php

namespace App\Enums;

enum SourceProvider: string
{
    case Ses = 'ses';
    case Cloudflare = 'cloudflare';

    public function label(): string
    {
        return match ($this) {
            self::Ses => 'Amazon SES',
            self::Cloudflare => 'Cloudflare Email Service',
        };
    }
}
