<?php

use App\Models\Source;

it('never serializes aws credentials or the webhook token', function () {
    $source = Source::make([
        'name' => 'Production',
        'aws_access_key_id' => 'AKIA-secret',
        'aws_secret_access_key' => 'super-secret-value',
        'aws_session_token' => 'session-secret',
        'webhook_token' => 'ses-token',
    ]);

    $array = $source->toArray();

    expect($array)
        ->not->toHaveKey('aws_access_key_id')
        ->not->toHaveKey('aws_secret_access_key')
        ->not->toHaveKey('aws_session_token')
        ->not->toHaveKey('webhook_token')
        ->and($array['name'])->toBe('Production');
});
