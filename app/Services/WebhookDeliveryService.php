<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\EmailEvent;

class WebhookDeliveryService
{
    public function dispatchFor(EmailEvent $event): void
    {
        $email = $event->email;

        if (! $email) {
            return;
        }

        $email->project
            ->webhookEndpoints()
            ->where('status', 'active')
            ->whereJsonContains('events', $event->event_type)
            ->each(function ($endpoint) use ($event): void {
                DeliverWebhook::dispatch($endpoint->id, $event->id)->onQueue('webhooks');
            });
    }
}
