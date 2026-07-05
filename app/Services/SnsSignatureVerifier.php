<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SnsSignatureVerifier
{
    /**
     * Verify that an SNS envelope was actually signed by AWS for the given region.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verify(array $payload, string $region): bool
    {
        $type = $payload['Type'] ?? null;
        $signature = $payload['Signature'] ?? null;
        $signingCertUrl = $payload['SigningCertURL'] ?? null;
        $signatureVersion = (string) ($payload['SignatureVersion'] ?? '1');

        if (! is_string($type) || ! is_string($signature) || ! is_string($signingCertUrl)) {
            return false;
        }

        if (! $this->isAllowedCertUrl($signingCertUrl, $region)) {
            return false;
        }

        $canonicalString = $this->canonicalStringFor($type, $payload);

        if ($canonicalString === null) {
            return false;
        }

        $certificate = $this->certificateFor($signingCertUrl);

        if ($certificate === null) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($certificate);

        if ($publicKey === false) {
            return false;
        }

        $decodedSignature = base64_decode($signature, true);

        if ($decodedSignature === false) {
            return false;
        }

        $algorithm = $signatureVersion === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;

        return openssl_verify($canonicalString, $decodedSignature, $publicKey, $algorithm) === 1;
    }

    private function isAllowedCertUrl(string $url, string $region): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        return $scheme === 'https'
            && is_string($host)
            && Str::lower($host) === "sns.{$region}.amazonaws.com"
            && Str::endsWith(Str::lower($path), '.pem');
    }

    private function certificateFor(string $url): ?string
    {
        $cached = Cache::get($this->cacheKeyFor($url));

        if (is_string($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(10)->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $certificate = $response->body();

        Cache::put($this->cacheKeyFor($url), $certificate, now()->addDay());

        return $certificate;
    }

    private function cacheKeyFor(string $url): string
    {
        return 'sns-signing-cert:'.md5($url);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalStringFor(string $type, array $payload): ?string
    {
        $fields = match ($type) {
            'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
            'SubscriptionConfirmation', 'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
            default => null,
        };

        if ($fields === null) {
            return null;
        }

        $canonical = '';

        foreach ($fields as $field) {
            if ($field === 'Subject' && ! array_key_exists('Subject', $payload)) {
                continue;
            }

            if (! array_key_exists($field, $payload) || ! is_scalar($payload[$field])) {
                return null;
            }

            $canonical .= $field."\n".$payload[$field]."\n";
        }

        return $canonical;
    }
}
