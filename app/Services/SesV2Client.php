<?php

namespace App\Services;

use App\Models\Source;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SesV2Client
{
    /**
     * @return array{mode: string, response: array<string, mixed>, tokens: array<int, string>}
     */
    public function createEmailIdentity(Source $source, string $domain): array
    {
        $target = "https://email.{$source->ses_region}.amazonaws.com/v2/email/identities";
        $payload = json_encode([
            'EmailIdentity' => $domain,
        ], JSON_THROW_ON_ERROR);

        $headers = $this->signedHeaders($source, 'POST', $target, $payload);
        $response = Http::withHeaders($headers)
            ->timeout(15)
            ->withBody($payload, 'application/json')
            ->post($target);

        try {
            $response->throw();
        } catch (RequestException $exception) {
            if (! $this->isExistingIdentityError($exception)) {
                throw $exception;
            }

            return $this->getEmailIdentity($source, $domain);
        }

        $json = $response->json();

        return [
            'mode' => 'aws',
            'response' => $json,
            'tokens' => $json['DkimAttributes']['Tokens'] ?? [],
        ];
    }

    /**
     * @return array{mode: string, response: array<string, mixed>, tokens: array<int, string>}
     */
    public function getEmailIdentity(Source $source, string $domain): array
    {
        $target = "https://email.{$source->ses_region}.amazonaws.com/v2/email/identities/{$domain}";
        $payload = '';
        $headers = $this->signedHeaders($source, 'GET', $target, $payload);
        $response = Http::withHeaders($headers)
            ->timeout(15)
            ->get($target);

        $response->throw();

        $json = $response->json();

        return [
            'mode' => 'aws',
            'response' => $json,
            'tokens' => $json['DkimAttributes']['Tokens'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccount(Source $source): array
    {
        $target = "https://email.{$source->ses_region}.amazonaws.com/v2/email/account";
        $payload = '';
        $headers = $this->signedHeaders($source, 'GET', $target, $payload);
        $response = Http::withHeaders($headers)
            ->timeout(15)
            ->get($target);

        $response->throw();

        return $response->json();
    }

    /**
     * The explicit Destination is required for Bcc delivery: Symfony Mime
     * strips the Bcc header from the raw message, so SES cannot derive
     * Bcc recipients from the MIME headers alone.
     *
     * @param  array{ToAddresses?: array<int, string>, CcAddresses?: array<int, string>, BccAddresses?: array<int, string>}  $destination
     * @return array{message_id: string|null, response: array<string, mixed>}
     */
    public function sendRawEmail(Source $source, string $mime, array $destination = []): array
    {
        $target = "https://email.{$source->ses_region}.amazonaws.com/v2/email/outbound-emails";
        $body = [
            'Content' => [
                'Raw' => [
                    'Data' => base64_encode($mime),
                ],
            ],
            'ConfigurationSetName' => $source->ses_configuration_set,
        ];

        if ($destination !== []) {
            $body['Destination'] = $destination;
        }

        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        $headers = $this->signedHeaders($source, 'POST', $target, $payload);
        $response = Http::withHeaders($headers)
            ->timeout(15)
            ->withBody($payload, 'application/json')
            ->post($target);

        $response->throw();

        $json = $response->json();

        return [
            'message_id' => $json['MessageId'] ?? null,
            'response' => $json,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(Source $source, string $method, string $url, string $payload): array
    {
        $credentials = $this->awsCredentials($source);
        $host = parse_url($url, PHP_URL_HOST);
        $amzDate = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $payloadHash = hash('sha256', $payload);
        $sessionToken = $credentials['session_token'] ?? null;
        $sessionHeader = filled($sessionToken) ? "x-amz-security-token:{$sessionToken}\n" : '';
        $canonicalHeaders = "content-type:application/json\nhost:{$host}\nx-amz-date:{$amzDate}\n{$sessionHeader}";
        $signedHeaders = filled($sessionToken)
            ? 'content-type;host;x-amz-date;x-amz-security-token'
            : 'content-type;host;x-amz-date';
        $canonicalRequest = implode("\n", [
            $method,
            parse_url($url, PHP_URL_PATH),
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "{$date}/{$source->ses_region}/ses/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->signatureKey($credentials['secret_access_key'], $date, $source->ses_region, 'ses');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers = [
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$credentials['access_key_id']}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
            'Content-Type' => 'application/json',
            'Host' => $host,
            'X-Amz-Date' => $amzDate,
        ];

        if (filled($sessionToken)) {
            $headers['X-Amz-Security-Token'] = $sessionToken;
        }

        return $headers;
    }

    /**
     * @return array{access_key_id: string, secret_access_key: string, session_token?: string}
     */
    private function awsCredentials(Source $source): array
    {
        if ($source->aws_access_key_id && $source->aws_secret_access_key) {
            return [
                'access_key_id' => $source->aws_access_key_id,
                'secret_access_key' => $source->aws_secret_access_key,
                'session_token' => $source->aws_session_token,
            ];
        }

        if (! app()->environment('production')) {
            throw new RuntimeException('Configure SES credentials or run Larasend on production infrastructure with an attached EC2 instance role.');
        }

        return $this->instanceRoleCredentials();
    }

    /**
     * @return array{access_key_id: string, secret_access_key: string, session_token: string}
     */
    private function instanceRoleCredentials(): array
    {
        try {
            $token = Http::withHeaders(['X-aws-ec2-metadata-token-ttl-seconds' => '21600'])
                ->timeout(2)
                ->put('http://169.254.169.254/latest/api/token')
                ->throw()
                ->body();

            $headers = ['X-aws-ec2-metadata-token' => $token];
            $role = trim(Http::withHeaders($headers)
                ->timeout(2)
                ->get('http://169.254.169.254/latest/meta-data/iam/security-credentials/')
                ->throw()
                ->body());

            $credentials = Http::withHeaders($headers)
                ->timeout(2)
                ->get("http://169.254.169.254/latest/meta-data/iam/security-credentials/{$role}")
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            throw new RuntimeException('Configure SES credentials or attach an EC2 instance role with SES permissions.', previous: $exception);
        }

        if (! is_array($credentials) || blank($credentials['AccessKeyId'] ?? null) || blank($credentials['SecretAccessKey'] ?? null)) {
            throw new RuntimeException('EC2 instance role credentials were not available.');
        }

        return [
            'access_key_id' => $credentials['AccessKeyId'],
            'secret_access_key' => $credentials['SecretAccessKey'],
            'session_token' => $credentials['Token'] ?? '',
        ];
    }

    private function isExistingIdentityError(RequestException $exception): bool
    {
        $response = $exception->response;
        $body = $response?->json() ?? [];
        $message = (string) ($body['message'] ?? $body['Message'] ?? $exception->getMessage());
        $type = (string) ($body['__type'] ?? $body['code'] ?? $body['Code'] ?? '');

        $normalizedMessage = Str::lower($message);

        return str_contains($type, 'AlreadyExists')
            || str_contains($message, 'AlreadyExists')
            || str_contains($normalizedMessage, 'already exists')
            || str_contains($normalizedMessage, 'already exist');
    }

    private function signatureKey(string $key, string $date, string $region, string $service): string
    {
        $dateKey = hash_hmac('sha256', $date, 'AWS4'.$key, binary: true);
        $dateRegionKey = hash_hmac('sha256', $region, $dateKey, binary: true);
        $dateRegionServiceKey = hash_hmac('sha256', $service, $dateRegionKey, binary: true);

        return hash_hmac('sha256', 'aws4_request', $dateRegionServiceKey, binary: true);
    }
}
