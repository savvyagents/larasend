<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

const SES_TEST_SIGNING_CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-test.pem';

function sesTestPrivateKey(): string
{
    return <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQCnrNCCyedLagqB
    VH39XqqdS33XSj9uWcOI/su7ZEsTtEcQQQfdgD0kNX8Ga6CorNnDjf7i+pkpkeI2
    z1J05IegmWBdJEM95U0lo3wKUzBpnGAuJPekvNmpMR0KCq0Y1yioeVmTUVv92ah9
    OSMbwkvpUN9mtWVrxsLP5B+AmUDCLh4jPwXt0YIToVwPYQTin3wsr2jXN1pyIAD8
    7qO3DA1D0fO8RM7KWuJWEkQQV8ZCwVy5txvwAMnpg9a93UU+fUy973PxN8O8J4Fw
    kR75r5iLXJxkTMP1UyTLiwd5UW/lr3Hr/f3VFu7uJzSOrMP61neU9J85nn0PzQt3
    bQmJ2D3LAgMBAAECggEAFuIO0sqbNj+Sj3PofdudjTnwQqFvZq/5b4jWZQya8mV4
    DU/Ssvf9YCOs4kNt+ZcdYQLP9koc/P9bz/8bUbieidxkulFom9nfXXOiSgZwZPli
    ZunEokbjraN2QxiR5wH2Gof1IZXI6Cv8Qpq5n+A0W/MdifbXkI3fqF+JrRM9M0K/
    aK3Mwv3rNppq9sJ95t/+9Nbwi2Sbj5pYkgFWpNsTEG/TgWsSr2XtnEa5iPhdHiWg
    jI7tePOXry+jctopoShpBzTrkYzeCzNPJsT7U8CSGGWaw+zesUFdY83gfxzpTLVL
    Nt2sIRHEDChfzK6C398617B+fdn3WomTFcu0zIUEAQKBgQDUd9/43Z03ieYhGWQa
    3N+gZPXwaXbzLQ6aOO5P4HBGxaDFWtS3C8702wSVf7dxlablzdM0xiN4VpN3nUD/
    Nt3wOxBI8BKh//1ItcXeWnlz0KxE+0XJwYmgQnRgMYM558hhT+tFdkGG8dLeU1Yg
    TfuVBz71/ZFPkVtV76Z84CtXqwKBgQDKB4BTkQsgRrU7Ypjx/2Xuaww1Cq9Cgy56
    GrrtiSn5dH4BdwCw3IaEi2p7SDbTWE1V4r76oVydDtYxQpFiGWyxs+6owim5Fs1q
    0R2gFfDS9KEjY1AtFu8b+PdjlqYgpnjtdORhZNjsZU4KhgjMeuCIIyNlC7k40GR/
    DROkPcYSYQKBgFUpu5fIVMU3PAwWXl66G9TxwvbbfzdX3xuIKFXSE476lkek8dBx
    JkJVK9A0wjYAE0bXqonjxcHtieyPFsd1mZ46PnvN5toSftbPLYHD6By5DuQTh9So
    A9894+HO1te2BUakPxy3mupZMfm1k7cdKvOAVQdK3Rz0mZSnm6dAP39DAoGAFdst
    5NyCKaG8pYoLJdMNgfeOwIZBY56xfoA5zMzT/6q8nwfyyS9yVhCHGoM7ey3IYAxB
    wPeH/23FelrLQ6OggLEIlrU6sk1nN7Eb1V/KR+gzLpLl75rVj18l1F5N5qZb2sSU
    mHzCgwCKqtTJSuXYOKIkiB/2j9DrrlsJzvDn5sECgYBfWRulJ3Mk72hqrfMd1N8r
    m5Zj0N9RgmHE9QO2qFWrmDHGeLyJTRmWsAqKOY1Oh8D7vN57P+aYObFzand02i7A
    hXWCVrtFKt9PdnIyDmdhuf6pi9M9E7DX6mfbnsqmqJ/arQyFdvQnmpO6RI4HvAJ3
    OSGXz4JQRpy/cVsyqVVX9g==
    -----END PRIVATE KEY-----
    PEM;
}

function sesTestPublicCertificate(): string
{
    return <<<'PEM'
    -----BEGIN PUBLIC KEY-----
    MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAp6zQgsnnS2oKgVR9/V6q
    nUt910o/blnDiP7Lu2RLE7RHEEEH3YA9JDV/BmugqKzZw43+4vqZKZHiNs9SdOSH
    oJlgXSRDPeVNJaN8ClMwaZxgLiT3pLzZqTEdCgqtGNcoqHlZk1Fb/dmofTkjG8JL
    6VDfZrVla8bCz+QfgJlAwi4eIz8F7dGCE6FcD2EE4p98LK9o1zdaciAA/O6jtwwN
    Q9HzvETOylriVhJEEFfGQsFcubcb8ADJ6YPWvd1FPn1Mve9z8TfDvCeBcJEe+a+Y
    i1ycZEzD9VMky4sHeVFv5a9x6/391Rbu7ic0jqzD+tZ3lPSfOZ59D80Ld20Jidg9
    ywIDAQAB
    -----END PUBLIC KEY-----
    PEM;
}

/**
 * Build a fully signed SNS envelope the way AWS would deliver one, using a
 * throwaway test keypair. `sesTestPublicCertificate()` must be faked as the
 * SigningCertURL response so the controller's verifier can validate it.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function sesSignedSnsEnvelope(string $type, array $fields): array
{
    $envelope = array_merge([
        'Type' => $type,
        'MessageId' => (string) Str::uuid(),
        'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:larasend-test',
        'Message' => 'Test SNS message body.',
        'Token' => 'test-token',
        'Timestamp' => now()->toIso8601String(),
        'SignatureVersion' => '1',
        'SigningCertURL' => SES_TEST_SIGNING_CERT_URL,
    ], $fields);

    $canonicalFields = $type === 'Notification'
        ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
        : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

    $canonical = '';

    foreach ($canonicalFields as $field) {
        if ($field === 'Subject' && ! array_key_exists('Subject', $envelope)) {
            continue;
        }

        $canonical .= $field."\n".$envelope[$field]."\n";
    }

    openssl_sign($canonical, $signature, sesTestPrivateKey(), OPENSSL_ALGO_SHA1);

    $envelope['Signature'] = base64_encode($signature);

    return $envelope;
}

function something()
{
    // ..
}
