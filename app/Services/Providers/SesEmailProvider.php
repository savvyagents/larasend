<?php

namespace App\Services\Providers;

use App\Enums\SourceProvider;
use App\Models\Source;
use App\Services\SesV2Client;
use Illuminate\Support\Arr;
use Throwable;

class SesEmailProvider implements EmailProvider
{
    public function __construct(private SesV2Client $sesClient) {}

    public function key(): SourceProvider
    {
        return SourceProvider::Ses;
    }

    public function hasSendingCredentials(Source $source): bool
    {
        // Production falls back to the EC2 instance role inside SesV2Client.
        return filled($source->aws_access_key_id) || app()->environment('production');
    }

    public function validateCredentials(Source $source): array
    {
        if (! $this->hasSendingCredentials($source)) {
            return [
                'ok' => false,
                'blockers' => [[
                    'code' => 'missing_credentials',
                    'message' => 'AWS access keys are required (or run Larasend on AWS with an attached instance role).',
                ]],
                'warnings' => [],
                'meta' => [],
            ];
        }

        try {
            $account = $this->sesClient->getAccount($source);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'blockers' => [[
                    'code' => 'ses_unreachable',
                    'message' => 'Could not reach SES with these credentials: '.$exception->getMessage(),
                ]],
                'warnings' => [],
                'meta' => [],
            ];
        }

        $warnings = [];
        $quota = $account['SendQuota'] ?? [];
        $meta = [
            'quota' => [
                'max_24_hour_send' => $quota['Max24HourSend'] ?? null,
                'max_send_rate' => $quota['MaxSendRate'] ?? null,
                'sent_last_24_hours' => $quota['SentLast24Hours'] ?? null,
                'period' => '24h',
            ],
        ];

        if (($account['ProductionAccessEnabled'] ?? true) === false) {
            $warnings[] = [
                'code' => 'ses_sandbox',
                'message' => 'This SES account is in sandbox mode: it can only send to verified addresses. Request production access in the AWS console to send to anyone.',
            ];
        }

        return [
            'ok' => true,
            'blockers' => [],
            'warnings' => $warnings,
            'meta' => $meta,
        ];
    }

    public function sendRawEmail(Source $source, string $mime, array $envelope): array
    {
        // The explicit Destination is required for Bcc delivery: the stored
        // MIME has no Bcc header, so SES cannot derive those recipients.
        $destination = array_filter([
            'ToAddresses' => $envelope['to'] ?? [],
            'CcAddresses' => $envelope['cc'] ?? [],
            'BccAddresses' => $envelope['bcc'] ?? [],
        ]);

        return $this->sesClient->sendRawEmail($source, $mime, $destination);
    }

    public function fetchQuota(Source $source): array
    {
        $account = $this->sesClient->getAccount($source);
        $quota = $account['SendQuota'] ?? $account;

        return [
            'max_24_hour_send' => $quota['Max24HourSend'] ?? $quota['max_24_hour_send'] ?? null,
            'max_send_rate' => $quota['MaxSendRate'] ?? $quota['max_send_rate'] ?? null,
            'sent_last_24_hours' => $quota['SentLast24Hours'] ?? $quota['sent_last_24_hours'] ?? null,
            'period' => '24h',
        ];
    }

    public function dnsRecordsForDomain(Source $source, string $domain): array
    {
        $identity = $this->sesClient->createEmailIdentity($source, $domain);

        return collect($identity['tokens'])
            ->map(fn (string $token) => [
                'type' => 'CNAME',
                'name' => "{$token}._domainkey.{$domain}",
                'value' => "{$token}.dkim.amazonses.com",
                'status' => 'pending',
            ])
            ->whenEmpty(fn ($records) => $records->push([
                'type' => 'TXT',
                'name' => "_amazonses.{$domain}",
                'value' => Arr::random(['created-by-larasend-local-mode']),
                'status' => 'ok',
            ]))
            ->values()
            ->all();
    }

    public function supportsIdentityCreation(): bool
    {
        return true;
    }

    public function supportsInboundEventWebhooks(): bool
    {
        return true;
    }

    public function supportsOpenClickTracking(): bool
    {
        return true;
    }

    public function supportsSuppressionSync(): bool
    {
        return false;
    }
}
