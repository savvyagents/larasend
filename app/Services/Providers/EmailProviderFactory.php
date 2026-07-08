<?php

namespace App\Services\Providers;

use App\Enums\SourceProvider;
use App\Models\Source;

class EmailProviderFactory
{
    public function forSource(Source $source): EmailProvider
    {
        return $this->for($source->provider ?? SourceProvider::Ses);
    }

    public function for(SourceProvider $provider): EmailProvider
    {
        return match ($provider) {
            SourceProvider::Ses => app(SesEmailProvider::class),
            SourceProvider::Cloudflare => app(CloudflareEmailProvider::class),
        };
    }
}
