<?php

namespace App\Services\Providers;

use App\Models\Source;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

class CloudflareSmtpTransportFactory
{
    /**
     * Cloudflare's authenticated SMTP submission endpoint. The username is
     * the literal string "api_token"; the password is the API token itself.
     */
    public function create(Source $source): TransportInterface
    {
        $transport = new EsmtpTransport('smtp.mx.cloudflare.net', 465, tls: true);
        $transport->setUsername('api_token');
        $transport->setPassword((string) $source->cloudflare_api_token);

        return $transport;
    }
}
