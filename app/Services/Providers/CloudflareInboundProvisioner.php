<?php

namespace App\Services\Providers;

use App\Models\Domain;
use App\Models\Source;
use App\Services\CloudflareApiClient;
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

    public function __construct(private CloudflareApiClient $apiClient) {}

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
            $this->apiClient->enableEmailRouting($source, $zone['id']);
            $this->apiClient->routeCatchAllToWorker($source, $zone['id'], self::WORKER_NAME);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Deployed the Worker but could not configure Email Routing: '.$exception->getMessage()
                    .' Add the "Email Routing Rules: Edit" permission to the API token, or point a routing rule at the "'.self::WORKER_NAME.'" Worker in the Cloudflare dashboard.',
                previous: $exception,
            );
        }

        $domain->forceFill(['inbound_enabled_at' => now()])->save();
    }

    public function workerCode(): string
    {
        return File::get(resource_path('cloudflare/inbound-email-worker.js'));
    }
}
