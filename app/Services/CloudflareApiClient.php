<?php

namespace App\Services;

use App\Models\Source;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareApiClient
{
    /**
     * @return array{value: int|float|null, unit: string|null}
     */
    public function getSendingLimits(Source $source): array
    {
        $response = $this->request($source)->get('/email/sending/limits');

        $this->ensureSuccessful($response);

        $quota = $response->json('result.quota') ?? [];

        return [
            'value' => $quota['value'] ?? null,
            'unit' => $quota['unit'] ?? null,
        ];
    }

    /**
     * All account-level suppressions, paginated to exhaustion.
     *
     * @return array<int, array{id: string, email: string, reason: string, created_at: string|null, expires_at: string|null}>
     */
    public function listSuppressions(Source $source): array
    {
        $suppressions = [];
        $page = 1;

        do {
            $response = $this->request($source)->get('/email/sending/suppression', [
                'page' => $page,
                'per_page' => 100,
                'order' => 'created_at',
                'direction' => 'asc',
            ]);

            $this->ensureSuccessful($response);

            $results = $response->json('result') ?? [];

            foreach ($results as $suppression) {
                $suppressions[] = [
                    'id' => (string) ($suppression['id'] ?? ''),
                    'email' => (string) ($suppression['email'] ?? ''),
                    'reason' => (string) ($suppression['reason'] ?? ''),
                    'created_at' => $suppression['created_at'] ?? null,
                    'expires_at' => $suppression['expires_at'] ?? null,
                ];
            }

            $page++;
        } while ($results !== []);

        return $suppressions;
    }

    /**
     * Whether the token authenticates at all (independent of permissions).
     * User-owned tokens answer on the profile verify endpoint; account-owned
     * tokens only answer on the account-scoped one.
     */
    public function tokenIsValid(Source $source): bool
    {
        $response = $this->rootRequest($source)->get('/user/tokens/verify');

        if ($response->successful() && $response->json('result.status') === 'active') {
            return true;
        }

        if (blank($source->cloudflare_account_id)) {
            return false;
        }

        $accountResponse = $this->rootRequest($source)
            ->get("/accounts/{$source->cloudflare_account_id}/tokens/verify");

        return $accountResponse->successful() && $accountResponse->json('result.status') === 'active';
    }

    /**
     * All zones the token can read, with their owning account.
     *
     * @return array<int, array{id: string, name: string, account_id: string|null, account_name: string|null}>
     */
    public function listZones(Source $source): array
    {
        $zones = [];
        $page = 1;

        do {
            $response = $this->rootRequest($source)->get('/zones', [
                'page' => $page,
                'per_page' => 50,
                'status' => 'active',
            ]);

            $this->ensureSuccessful($response);

            $results = $response->json('result') ?? [];

            foreach ($results as $zone) {
                $zones[] = [
                    'id' => (string) ($zone['id'] ?? ''),
                    'name' => (string) ($zone['name'] ?? ''),
                    'account_id' => $zone['account']['id'] ?? null,
                    'account_name' => $zone['account']['name'] ?? null,
                ];
            }

            $page++;
        } while ($results !== [] && count($results) === 50);

        return $zones;
    }

    /**
     * Find the Cloudflare zone containing the domain by walking suffixes,
     * e.g. mail.example.com -> example.com.
     *
     * @return array{id: string, name: string, account_id: string|null}|null
     */
    public function findZone(Source $source, string $domain): ?array
    {
        $labels = explode('.', $domain);

        while (count($labels) >= 2) {
            $candidate = implode('.', $labels);
            $response = $this->rootRequest($source)->get('/zones', ['name' => $candidate]);

            $this->ensureSuccessful($response);

            $zone = $response->json('result.0');

            if (is_array($zone) && filled($zone['id'] ?? null)) {
                return [
                    'id' => (string) $zone['id'],
                    'name' => (string) $zone['name'],
                    'account_id' => $zone['account']['id'] ?? null,
                ];
            }

            array_shift($labels);
        }

        return null;
    }

    /**
     * Onboard the domain for Email Sending, or return it if already onboarded.
     *
     * @return array{tag: string, name: string, enabled: bool, dkim_selector: string|null}
     */
    public function findOrCreateSendingSubdomain(Source $source, string $zoneId, string $domain): array
    {
        $existing = $this->rootRequest($source)->get("/zones/{$zoneId}/email/sending/subdomains");

        $this->ensureSuccessful($existing);

        $match = collect($existing->json('result') ?? [])
            ->first(fn (array $subdomain): bool => strcasecmp((string) ($subdomain['name'] ?? ''), $domain) === 0
                && ($subdomain['enabled'] ?? false) === true);

        if ($match === null) {
            $created = $this->rootRequest($source)->post("/zones/{$zoneId}/email/sending/subdomains", [
                'name' => $domain,
            ]);

            $this->ensureSuccessful($created);

            $match = $created->json('result') ?? [];
        }

        return [
            'tag' => (string) ($match['tag'] ?? ''),
            'name' => (string) ($match['name'] ?? $domain),
            'enabled' => (bool) ($match['enabled'] ?? false),
            'dkim_selector' => $match['dkim_selector'] ?? null,
        ];
    }

    /**
     * The DNS records Cloudflare expects to exist for a sending subdomain.
     *
     * @return array<int, array{type: string, name: string, content: string, priority: int|null}>
     */
    public function getSendingSubdomainDns(Source $source, string $zoneId, string $subdomainTag): array
    {
        $response = $this->rootRequest($source)->get("/zones/{$zoneId}/email/sending/subdomains/{$subdomainTag}/dns");

        $this->ensureSuccessful($response);

        return collect($response->json('result') ?? [])
            ->map(fn (array $record): array => [
                'type' => strtoupper((string) ($record['type'] ?? 'TXT')),
                'name' => (string) ($record['name'] ?? ''),
                'content' => (string) ($record['content'] ?? ''),
                'priority' => $record['priority'] ?? null,
            ])
            ->filter(fn (array $record): bool => $record['name'] !== '' && $record['content'] !== '')
            ->values()
            ->all();
    }

    /**
     * Create any of the expected DNS records that are missing from the zone.
     * Records that already exist are skipped silently.
     *
     * @param  array<int, array{type: string, name: string, content: string, priority: int|null}>  $records
     */
    public function ensureDnsRecords(Source $source, string $zoneId, array $records): void
    {
        foreach ($records as $record) {
            $payload = [
                'type' => $record['type'],
                'name' => $record['name'],
                'content' => $record['content'],
                'ttl' => 1,
            ];

            if ($record['priority'] !== null) {
                $payload['priority'] = (int) $record['priority'];
            }

            $response = $this->rootRequest($source)->post("/zones/{$zoneId}/dns_records", $payload);

            if ($response->successful()) {
                continue;
            }

            // 81057/81058: an identical record already exists — not a failure.
            $alreadyExists = collect($response->json('errors') ?? [])
                ->contains(fn (array $error): bool => in_array($error['code'] ?? null, [81057, 81058], true));

            if (! $alreadyExists) {
                $this->ensureSuccessful($response);
            }
        }
    }

    private function request(Source $source): PendingRequest
    {
        return Http::withToken((string) $source->cloudflare_api_token)
            ->baseUrl("https://api.cloudflare.com/client/v4/accounts/{$source->cloudflare_account_id}")
            ->acceptJson()
            ->timeout(15);
    }

    private function rootRequest(Source $source): PendingRequest
    {
        return Http::withToken((string) $source->cloudflare_api_token)
            ->baseUrl('https://api.cloudflare.com/client/v4')
            ->acceptJson()
            ->timeout(15);
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $errors = collect($response->json('errors') ?? []);

        if ($errors->contains(fn (array $error): bool => ($error['code'] ?? null) === 10105)) {
            throw new RuntimeException('This Cloudflare account is not entitled to Email Sending. It requires the Workers Paid plan and a domain onboarded for Email Sending.');
        }

        if ($errors->contains(fn (array $error): bool => ($error['code'] ?? null) === 10102)) {
            throw new RuntimeException('The Cloudflare API token is missing the "Email Sending: Edit" permission.');
        }

        if ($response->status() === 401) {
            throw new RuntimeException('Cloudflare rejected the API token. The token may be invalid, missing a required permission, or the account may not have Email Sending enabled (requires the Workers Paid plan).');
        }

        $message = $errors->pluck('message')->filter()->implode('; ');

        throw new RuntimeException($message !== ''
            ? "Cloudflare API error: {$message}"
            : "Cloudflare API request failed with status {$response->status()}.");
    }
}
