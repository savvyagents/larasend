<?php

use App\Enums\SourceProvider;
use App\Models\Source;
use App\Services\Providers\CloudflareEmailProvider;
use App\Services\Providers\EmailProviderFactory;
use App\Services\Providers\SesEmailProvider;

it('resolves the ses driver for sources without an explicit provider', function () {
    $source = new Source;

    $provider = app(EmailProviderFactory::class)->forSource($source);

    expect($provider)->toBeInstanceOf(SesEmailProvider::class)
        ->and($provider->key())->toBe(SourceProvider::Ses)
        ->and($provider->supportsIdentityCreation())->toBeTrue()
        ->and($provider->supportsInboundEventWebhooks())->toBeTrue()
        ->and($provider->supportsSuppressionSync())->toBeFalse();
});

it('resolves the cloudflare driver for cloudflare sources', function () {
    $source = new Source(['provider' => SourceProvider::Cloudflare]);

    $provider = app(EmailProviderFactory::class)->forSource($source);

    expect($provider)->toBeInstanceOf(CloudflareEmailProvider::class)
        ->and($provider->key())->toBe(SourceProvider::Cloudflare)
        ->and($provider->supportsIdentityCreation())->toBeFalse()
        ->and($provider->supportsInboundEventWebhooks())->toBeFalse()
        ->and($provider->supportsOpenClickTracking())->toBeFalse()
        ->and($provider->supportsSuppressionSync())->toBeTrue();
});

it('requires a token and account id for cloudflare sending credentials in every environment', function () {
    $factory = app(EmailProviderFactory::class);

    $withCredentials = new Source([
        'provider' => SourceProvider::Cloudflare,
        'cloudflare_api_token' => 'cf-token',
        'cloudflare_account_id' => 'acc-1',
    ]);
    $withoutCredentials = new Source(['provider' => SourceProvider::Cloudflare]);

    expect($factory->forSource($withCredentials)->hasSendingCredentials($withCredentials))->toBeTrue()
        ->and($factory->forSource($withoutCredentials)->hasSendingCredentials($withoutCredentials))->toBeFalse();
});
