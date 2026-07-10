<?php

namespace App\Services\Providers;

use App\Models\Domain;
use App\Models\Source;
use App\Services\CloudflareApiClient;
use App\Services\DnsRecordVerifier;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Turns on inbound email for a domain with zero manual Cloudflare steps:
 * uploads the passthrough Worker, enables Email Routing on the zone, and
 * points the catch-all rule at the Worker. Failures throw with the exact
 * missing piece plus manual instructions, mirroring how automatic domain
 * onboarding degrades on the sending side.
 */
class CloudflareInboundProvisioner
{
    public const WORKER_NAME = 'larasend-inbound';

    /**
     * The MX records Cloudflare Email Routing expects at the zone apex.
     */
    private const ROUTING_MX = [
        ['priority' => 20, 'target' => 'route1.mx.cloudflare.net'],
        ['priority' => 59, 'target' => 'route2.mx.cloudflare.net'],
        ['priority' => 99, 'target' => 'route3.mx.cloudflare.net'],
    ];

    public function __construct(
        private CloudflareApiClient $apiClient,
        private DnsRecordVerifier $dnsVerifier,
    ) {}

    public function enable(Source $source, Domain $domain): void
    {
        $zone = $this->apiClient->findZone($source, $domain->domain);

        if ($zone === null) {
            throw new RuntimeException(
                "No Cloudflare zone found for \"{$domain->domain}\". The domain must use Cloudflare DNS on the account the API token belongs to.",
            );
        }

        try {
            $this->apiClient->uploadWorker($source, self::WORKER_NAME, $this->workerCode(), [
                'LARASEND_INBOUND_URL' => route('webhooks.inbound.cloudflare', $source->webhook_token),
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Could not deploy the inbound Worker: '.$exception->getMessage()
                    .' Add the "Workers Scripts: Edit" permission to the API token, or deploy the Worker manually with wrangler.',
                previous: $exception,
            );
        }

        try {
            $this->apiClient->routeCatchAllToWorker($source, $zone['id'], self::WORKER_NAME);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Deployed the Worker but could not configure Email Routing: '.$exception->getMessage()
                    .' Add the "Email Routing Rules: Edit" permission to the API token, or point a routing rule at the "'.self::WORKER_NAME.'" Worker in the Cloudflare dashboard.',
                previous: $exception,
            );
        }

        $this->ensureRoutingDns($source, $zone['id'], $zone['name']);

        $domain->forceFill(['inbound_enabled_at' => now()])->save();
    }

    /**
     * Delivery needs the routing MX records at the zone apex. The explicit
     * enable endpoint is gated by a settings-level permission most tokens
     * lack, so it is best-effort only; when the MX records are still missing
     * afterwards they are published directly via DNS (which the token can
     * already edit), and only an unresolvable state throws.
     */
    private function ensureRoutingDns(Source $source, string $zoneId, string $zoneName): void
    {
        try {
            $this->apiClient->enableEmailRouting($source, $zoneId);
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($this->routingMxPresent($zoneName)) {
            return;
        }

        try {
            $this->apiClient->ensureDnsRecords($source, $zoneId, collect(self::ROUTING_MX)
                ->map(fn (array $mx): array => [
                    'type' => 'MX',
                    'name' => $zoneName,
                    'content' => $mx['target'],
                    'priority' => $mx['priority'],
                ])
                ->all());
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'The routing rule is configured, but the zone apex has no Email Routing MX records and they could not be created: '
                    .$exception->getMessage()
                    .' Enable Email Routing on the zone in the Cloudflare dashboard (Compute & AI > Email Service > Email Routing) to finish setup.',
                previous: $exception,
            );
        }
    }

    private function routingMxPresent(string $zoneName): bool
    {
        return $this->dnsVerifier->matches([
            'type' => 'MX',
            'name' => $zoneName,
            'value' => 'route1.mx.cloudflare.net',
        ]);
    }

    public function workerCode(): string
    {
        return File::get(resource_path('cloudflare/inbound-email-worker.js'));
    }
}
