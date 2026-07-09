<?php

namespace App\Services\Providers;

use App\Enums\SourceProvider;
use App\Models\Source;
use App\Services\CloudflareApiClient;
use App\Services\MimeMessageBuilder;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Throwable;

class CloudflareEmailProvider implements EmailProvider
{
    /**
     * Fallback DKIM selector for manual verification records, used only when
     * the token lacks zone permissions so the real records cannot be fetched
     * from the sending-subdomain DNS endpoint.
     */
    private const DKIM_SELECTOR = 'cf2024-1';

    public function __construct(
        private CloudflareApiClient $apiClient,
        private CloudflareSmtpTransportFactory $transportFactory,
        private MimeMessageBuilder $mimeBuilder,
    ) {}

    public function key(): SourceProvider
    {
        return SourceProvider::Cloudflare;
    }

    public function hasSendingCredentials(Source $source): bool
    {
        // Unlike SES there is no instance-role fallback: a token is
        // always required, in every environment.
        return filled($source->cloudflare_api_token) && filled($source->cloudflare_account_id);
    }

    public function validateCredentials(Source $source): array
    {
        $blockers = [];
        $warnings = [];
        $meta = [];

        if (blank($source->cloudflare_api_token)) {
            return [
                'ok' => false,
                'blockers' => [[
                    'code' => 'missing_token',
                    'message' => 'A Cloudflare API token is required.',
                ]],
                'warnings' => [],
                'meta' => [],
            ];
        }

        $zones = null;

        try {
            $zones = $this->apiClient->listZones($source);
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($zones === null) {
            if (! $this->apiClient->tokenIsValid($source)) {
                return [
                    'ok' => false,
                    'blockers' => [[
                        'code' => 'invalid_token',
                        'message' => 'Cloudflare rejected the API token. Check that it was copied completely and has not been revoked.',
                    ]],
                    'warnings' => [],
                    'meta' => [],
                ];
            }

            $warnings[] = [
                'code' => 'no_zone_read',
                'message' => 'The token cannot read zones, so Larasend cannot onboard sending domains automatically. Add the "Zone: Read" and "DNS: Edit" permissions, or onboard domains manually in the Cloudflare dashboard.',
            ];
        } elseif ($zones === []) {
            $warnings[] = [
                'code' => 'no_zones',
                'message' => 'The token is valid but no active zones are visible to it. Your sending domain must be a zone on this Cloudflare account.',
            ];
        } else {
            $meta['zones'] = $zones;
            $accountIds = collect($zones)->pluck('account_id')->filter()->unique();

            if ($accountIds->count() === 1) {
                $meta['account_id'] = $accountIds->first();
            }
        }

        $accountId = $source->cloudflare_account_id ?: ($meta['account_id'] ?? null);

        if (blank($accountId)) {
            $blockers[] = [
                'code' => 'missing_account',
                'message' => 'Could not determine the Cloudflare account for this token. Enter the account ID manually in the source settings.',
            ];
        } else {
            $meta['account_id'] ??= $accountId;

            try {
                $limits = $this->apiClient->getSendingLimits($this->withAccountId($source, (string) $accountId));
                $meta['quota'] = [
                    'max_24_hour_send' => $limits['value'],
                    'max_send_rate' => null,
                    'sent_last_24_hours' => null,
                    'period' => $limits['unit'] ?? 'day',
                ];
            } catch (Throwable $exception) {
                $blockers[] = [
                    'code' => 'email_sending_unavailable',
                    'message' => $this->explainEmailSendingFailure($exception),
                ];
            }
        }

        return [
            'ok' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'meta' => $meta,
        ];
    }

    private function withAccountId(Source $source, string $accountId): Source
    {
        if ($source->cloudflare_account_id === $accountId) {
            return $source;
        }

        $probe = $source->replicate(['webhook_token']);
        $probe->cloudflare_account_id = $accountId;

        return $probe;
    }

    private function explainEmailSendingFailure(Throwable $exception): string
    {
        $message = $exception->getMessage();

        // The specific 403 entitlement/permission codes already map to clear
        // messages in CloudflareApiClient. A generic 401 is ambiguous between
        // a missing token permission and a missing Workers Paid plan, so say
        // both — this exact ambiguity once cost an afternoon.
        if (str_contains($message, 'rejected the API token')) {
            return 'Cloudflare denied access to Email Sending. Check both: (1) the API token has the "Email Sending: Edit" permission, and (2) the account is on the Workers Paid plan with Email Sending enabled (Compute & AI > Email Service).';
        }

        return $message;
    }

    public function sendRawEmail(Source $source, string $mime, array $envelope): array
    {
        $sender = $this->mimeBuilder->address($envelope['from']);
        $recipients = array_map(
            fn (string $recipient): Address => $this->mimeBuilder->address($recipient),
            $envelope['recipients'],
        );

        if ($recipients === []) {
            throw new RuntimeException('Cannot send: the email has no recipients.');
        }

        $transport = $this->transportFactory->create($source);

        try {
            $sent = $transport->send(new RawMessage($mime), new Envelope($sender, $recipients));
        } catch (TransportExceptionInterface $exception) {
            throw $this->mapTransportException($exception, $sender);
        }

        $transcript = $this->sanitizeSmtpTranscript(trim((string) $sent?->getDebug()));

        return [
            'message_id' => $this->extractMessageId($mime),
            'response' => array_filter([
                'provider' => 'cloudflare',
                'remote_id' => $this->extractRemoteQueueId($transcript),
                'smtp' => $transcript ?: null,
            ]),
        ];
    }

    /**
     * The raw SMTP debug transcript contains the AUTH exchange, whose base64
     * payloads decode to the literal API token. Redact every client line in
     * the authentication phase before the transcript is persisted anywhere.
     */
    public function sanitizeSmtpTranscript(string $transcript): string
    {
        $sanitized = [];
        $inAuth = false;

        foreach (preg_split('/\r\n|\n/', $transcript) ?: [] as $line) {
            if (preg_match('/> AUTH\b.*$/i', $line)) {
                $inAuth = true;
                $sanitized[] = preg_replace('/(> AUTH \S+).*$/i', '$1 [redacted]', $line);

                continue;
            }

            if ($inAuth && str_contains($line, '> ')) {
                $sanitized[] = preg_replace('/> .*$/', '> [redacted]', $line);

                continue;
            }

            if ($inAuth && preg_match('/< (235|535|501)/', $line)) {
                $inAuth = false;
            }

            $sanitized[] = $line;
        }

        return implode("\n", $sanitized);
    }

    private function extractRemoteQueueId(string $transcript): ?string
    {
        if (preg_match('/250 2\.0\.0 Ok <([^>]+)>/', $transcript, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public function fetchQuota(Source $source): array
    {
        $limits = $this->apiClient->getSendingLimits($source);

        return [
            'max_24_hour_send' => $limits['value'],
            'max_send_rate' => null,
            'sent_last_24_hours' => null,
            'period' => $limits['unit'] ?? 'day',
        ];
    }

    public function dnsRecordsForDomain(Source $source, string $domain): array
    {
        // Onboard the domain for Email Sending through the zone API so no
        // manual Cloudflare dashboard step is needed. When that fails, the
        // thrown exception carries manual verification records so callers can
        // degrade gracefully — visibly, not silently.
        try {
            return $this->onboardSendingDomain($source, $domain);
        } catch (Throwable $exception) {
            report($exception);

            throw new DomainOnboardingException(
                'Automatic Cloudflare onboarding failed: '.$exception->getMessage()
                    .' The domain was saved for manual setup — onboard it in the Cloudflare dashboard (Compute & AI > Email Service), then re-check DNS.',
                $this->manualVerificationRecords($domain),
                $exception,
            );
        }
    }

    /**
     * @return array<int, array{type: string, name: string, value: string, status: string}>
     */
    private function onboardSendingDomain(Source $source, string $domain): array
    {
        $zone = $this->apiClient->findZone($source, $domain);

        if ($zone === null) {
            throw new RuntimeException(
                "No Cloudflare zone found for \"{$domain}\". The domain must use Cloudflare DNS on the account the API token belongs to.",
            );
        }

        $subdomain = $this->apiClient->findOrCreateSendingSubdomain($source, $zone['id'], $domain);
        $expected = $this->apiClient->getSendingSubdomainDns($source, $zone['id'], $subdomain['tag']);

        try {
            $this->apiClient->ensureDnsRecords($source, $zone['id'], $expected);
        } catch (Throwable $exception) {
            // The expected records are still accurate; the user can publish
            // them by hand if the token cannot edit DNS.
            report($exception);
        }

        return collect($expected)
            ->map(fn (array $record): array => [
                'type' => $record['type'],
                'name' => $record['name'] === '@' ? $zone['name'] : $record['name'],
                'value' => $record['priority'] !== null
                    ? trim($record['priority'].' '.$record['content'])
                    : $record['content'],
                'status' => 'pending',
            ])
            ->whenEmpty(fn ($records) => $records->push(...$this->manualVerificationRecords($domain)))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{type: string, name: string, value: string, status: string}>
     */
    private function manualVerificationRecords(string $domain): array
    {
        return [
            [
                'type' => 'TXT',
                'name' => $domain,
                'value' => '_spf.mx.cloudflare.net',
                'status' => 'pending',
            ],
            [
                'type' => 'TXT',
                'name' => self::DKIM_SELECTOR."._domainkey.{$domain}",
                'value' => 'v=DKIM1',
                'status' => 'pending',
            ],
        ];
    }

    public function supportsIdentityCreation(): bool
    {
        return false;
    }

    public function supportsInboundEventWebhooks(): bool
    {
        return false;
    }

    public function supportsOpenClickTracking(): bool
    {
        return false;
    }

    public function supportsSuppressionSync(): bool
    {
        return true;
    }

    private function mapTransportException(TransportExceptionInterface $exception, Address $sender): Throwable
    {
        $message = $exception->getMessage();

        if (str_contains($message, '550') && str_contains($message, '5.7.1')) {
            $domain = substr($sender->getAddress(), (int) strrpos($sender->getAddress(), '@') + 1);

            return new RuntimeException(
                "Cloudflare rejected the sender address. Add \"{$domain}\" as a sending domain in Larasend (or onboard it for Email Sending in the Cloudflare dashboard), then try again.",
                previous: $exception,
            );
        }

        if (str_contains($message, '535')) {
            return new RuntimeException(
                'Cloudflare rejected the API token over SMTP. Check that the token is valid and has the "Email Sending: Edit" permission.',
                previous: $exception,
            );
        }

        // Transient failures keep their original type so the queue's
        // retry/backoff machinery treats them normally.
        return $exception;
    }

    private function extractMessageId(string $mime): ?string
    {
        $headers = str_contains($mime, "\r\n\r\n")
            ? strstr($mime, "\r\n\r\n", before_needle: true)
            : $mime;

        if (preg_match('/^Message-ID:\s*<([^>]+)>/mi', (string) $headers, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
