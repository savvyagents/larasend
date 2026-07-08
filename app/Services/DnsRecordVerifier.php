<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Support\Facades\Http;
use Throwable;

class DnsRecordVerifier
{
    /**
     * DNS-over-HTTPS resolvers, tried in order. DoH bypasses the host's
     * stub resolver, whose negative caching otherwise reports records as
     * missing for many minutes right after a provider publishes them —
     * exactly the window in which Larasend verifies a new domain.
     */
    private const DOH_ENDPOINTS = [
        'https://cloudflare-dns.com/dns-query',
        'https://dns.google/resolve',
    ];

    private const DOH_TYPE_CODES = [
        'CNAME' => 5,
        'MX' => 15,
        'TXT' => 16,
    ];

    /**
     * Re-checks every DNS record for a domain, persists the per-record and
     * overall status, and returns whether the domain is now fully verified.
     * Shared by the manual "Re-check DNS" action and the scheduled
     * background recheck so both stay in sync.
     */
    public function recheck(Domain $domain): bool
    {
        $records = collect($domain->dns_records ?? [])
            ->map(fn (array $record) => [
                ...$record,
                'status' => $this->matches($record) ? 'ok' : 'pending',
            ])
            ->values()
            ->all();

        $allRecordsPass = collect($records)->every(fn (array $record) => ($record['status'] ?? null) === 'ok');

        $domain->forceFill([
            'status' => $allRecordsPass ? 'verified' : 'pending',
            'dns_records' => $records,
            'verified_at' => $allRecordsPass ? now() : null,
        ])->save();

        return $allRecordsPass;
    }

    /**
     * @param  array<string, string>  $record
     */
    public function matches(array $record): bool
    {
        $type = strtoupper((string) ($record['type'] ?? ''));
        $host = (string) ($record['name'] ?? '');
        $expected = $this->normalize((string) ($record['value'] ?? ''));

        if ($host === '' || $expected === '') {
            return false;
        }

        foreach ($this->lookup($host, $type) as $actual) {
            if ($actual !== '' && $this->valuesMatch($actual, $expected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve normalized answer values for a host/type, preferring DoH and
     * falling back to the system resolver when no DoH endpoint responds.
     *
     * @return array<int, string>
     */
    private function lookup(string $host, string $type): array
    {
        $typeCode = self::DOH_TYPE_CODES[$type] ?? self::DOH_TYPE_CODES['TXT'];

        foreach (self::DOH_ENDPOINTS as $endpoint) {
            try {
                $response = Http::withHeaders(['Accept' => 'application/dns-json'])
                    ->timeout(5)
                    ->get($endpoint, ['name' => $host, 'type' => $type]);
            } catch (Throwable) {
                continue;
            }

            if (! $response->successful() || ! is_array($response->json())) {
                continue;
            }

            return collect($response->json('Answer') ?? [])
                ->filter(fn (array $answer): bool => (int) ($answer['type'] ?? 0) === $typeCode)
                ->map(fn (array $answer): string => $this->normalize((string) ($answer['data'] ?? '')))
                ->filter()
                ->values()
                ->all();
        }

        return $this->systemLookup($host, $type);
    }

    /**
     * @return array<int, string>
     */
    private function systemLookup(string $host, string $type): array
    {
        $answers = dns_get_record($host, match ($type) {
            'CNAME' => DNS_CNAME,
            'MX' => DNS_MX,
            default => DNS_TXT,
        });

        if ($answers === false) {
            return [];
        }

        return collect($answers)
            ->map(fn (array $answer): string => match ($type) {
                'CNAME' => $this->normalize((string) ($answer['target'] ?? '')),
                'MX' => $this->normalize(trim((string) ($answer['pri'] ?? '').' '.(string) ($answer['target'] ?? ''))),
                default => $this->normalize(implode('', $answer['entries'] ?? [(string) ($answer['txt'] ?? '')])),
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Lowercase, unquote, and strip trailing dots so provider-formatted
     * expectations compare cleanly against resolver answers. Long TXT
     * records arrive as multiple quoted character-strings; the quote
     * stripping also rejoins those into one value.
     */
    private function normalize(string $value): string
    {
        return rtrim(trim(str_replace(['" "', '""', '"'], '', strtolower($value))), '.');
    }

    private function valuesMatch(string $actual, string $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }

        // Any published DMARC policy satisfies domain alignment; providers
        // suggest a policy (e.g. p=reject) but a pre-existing p=none or
        // p=quarantine record is just as valid for verification.
        if (str_contains($expected, 'v=dmarc1') && str_contains($actual, 'v=dmarc1')) {
            return true;
        }

        return str_contains($actual, $expected) || str_contains($expected, $actual);
    }
}
