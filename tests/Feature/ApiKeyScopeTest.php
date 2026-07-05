<?php

use App\Models\ApiKey;

it('grants full access when scopes were never recorded', function () {
    $apiKey = ApiKey::make(['scopes' => null]);

    expect($apiKey->allows('send'))->toBeTrue()
        ->and($apiKey->allows('read:activity'))->toBeTrue();
});

it('denies everything when scopes is explicitly empty', function () {
    $apiKey = ApiKey::make(['scopes' => []]);

    expect($apiKey->allows('send'))->toBeFalse()
        ->and($apiKey->allows('read:activity'))->toBeFalse();
});

it('only allows the scopes it was granted', function () {
    $apiKey = ApiKey::make(['scopes' => ['read:activity']]);

    expect($apiKey->allows('read:activity'))->toBeTrue()
        ->and($apiKey->allows('send'))->toBeFalse();
});
