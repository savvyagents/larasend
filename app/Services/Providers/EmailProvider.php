<?php

namespace App\Services\Providers;

use App\Enums\SourceProvider;
use App\Models\Source;

interface EmailProvider
{
    public function key(): SourceProvider;

    /**
     * Whether the source can authenticate to the provider right now.
     */
    public function hasSendingCredentials(Source $source): bool;

    /**
     * Probe the provider with the source's credentials and report anything
     * that would stop email from sending, before the user finds out the
     * hard way. Blockers prevent sending entirely; warnings degrade the
     * experience but sending still works. Meta carries useful facts learned
     * during the probes (zones, derived account id, quota, sandbox flag).
     *
     * @return array{ok: bool, blockers: array<int, array{code: string, message: string}>, warnings: array<int, array{code: string, message: string}>, meta: array<string, mixed>}
     */
    public function validateCredentials(Source $source): array;

    /**
     * Send a fully built raw MIME message. The envelope carries the sender
     * and recipients both flat and grouped by type, because bcc recipients
     * are never present in the MIME headers: SMTP providers use the flat
     * list as RCPT TO, SES builds an explicit Destination from the groups.
     *
     * @param  array{from: string, recipients: array<int, string>, to: array<int, string>, cc: array<int, string>, bcc: array<int, string>}  $envelope
     * @return array{message_id: string|null, response: array<string, mixed>}
     */
    public function sendRawEmail(Source $source, string $mime, array $envelope): array;

    /**
     * Provider quota normalized to the shape stored in sources.last_quota.
     *
     * @return array{max_24_hour_send: int|float|null, max_send_rate: int|float|null, sent_last_24_hours: int|float|null, period: string}
     */
    public function fetchQuota(Source $source): array;

    /**
     * DNS records required for the domain to send, in the shape stored on
     * Domain->dns_records and verified by DnsRecordVerifier.
     *
     * @return array<int, array{type: string, name: string, value: string, status: string}>
     */
    public function dnsRecordsForDomain(Source $source, string $domain): array;

    public function supportsIdentityCreation(): bool;

    public function supportsInboundEventWebhooks(): bool;

    public function supportsOpenClickTracking(): bool;

    public function supportsSuppressionSync(): bool;
}
