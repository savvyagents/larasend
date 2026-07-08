<?php

use App\Services\DnsRecordVerifier;
use Illuminate\Support\Facades\Http;

it('verifies long dkim records split into multiple txt chunks', function () {
    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response([
            'Status' => 0,
            'Answer' => [[
                'name' => 'cf-bounce._domainkey.mail.example.com',
                'type' => 16,
                'data' => '"v=DKIM1; h=sha256; k=rsa; p=AAAABBBB" "CCCCDDDD"',
            ]],
        ]),
    ]);

    $matches = app(DnsRecordVerifier::class)->matches([
        'type' => 'TXT',
        'name' => 'cf-bounce._domainkey.mail.example.com',
        'value' => '"v=DKIM1; h=sha256; k=rsa; p=AAAABBBBCCCCDDDD"',
    ]);

    expect($matches)->toBeTrue();
});

it('accepts any published dmarc policy even when the provider suggests another', function () {
    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response([
            'Status' => 0,
            'Answer' => [[
                'name' => '_dmarc.mail.example.com',
                'type' => 16,
                'data' => '"v=DMARC1;p=none;"',
            ]],
        ]),
    ]);

    $matches = app(DnsRecordVerifier::class)->matches([
        'type' => 'TXT',
        'name' => '_dmarc.mail.example.com',
        'value' => '"v=DMARC1; p=reject;"',
    ]);

    expect($matches)->toBeTrue();
});

it('verifies mx records with priority through doh', function () {
    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response([
            'Status' => 0,
            'Answer' => [[
                'name' => 'cf-bounce.mail.example.com',
                'type' => 15,
                'data' => '20 route1.mx.cloudflare.net.',
            ]],
        ]),
    ]);

    $matches = app(DnsRecordVerifier::class)->matches([
        'type' => 'MX',
        'name' => 'cf-bounce.mail.example.com',
        'value' => '20 route1.mx.cloudflare.net.',
    ]);

    expect($matches)->toBeTrue();
});

it('falls back to the second doh resolver when the first fails', function () {
    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response(null, 500),
        'https://dns.google/*' => Http::response([
            'Status' => 0,
            'Answer' => [[
                'name' => 'mail.example.com',
                'type' => 16,
                'data' => '"v=spf1 include:_spf.mx.cloudflare.net ~all"',
            ]],
        ]),
    ]);

    $matches = app(DnsRecordVerifier::class)->matches([
        'type' => 'TXT',
        'name' => 'mail.example.com',
        'value' => '_spf.mx.cloudflare.net',
    ]);

    expect($matches)->toBeTrue();
});

it('reports pending when doh finds no record', function () {
    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response(['Status' => 3, 'Answer' => []]),
    ]);

    $matches = app(DnsRecordVerifier::class)->matches([
        'type' => 'TXT',
        'name' => 'missing.example.com',
        'value' => 'v=spf1 something',
    ]);

    expect($matches)->toBeFalse();
});
