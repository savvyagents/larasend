<?php

namespace Larasend\Laravel;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class LarasendClient
{
    public function __construct(
        private ?string $apiKey,
        private string $endpoint,
        private int $timeout = 15,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function send(array $payload): array
    {
        return $this->http()
            ->post('/api/emails', $payload)
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $emailId): array
    {
        return $this->http()
            ->get("/api/emails/{$emailId}")
            ->throw()
            ->json();
    }

    public function emails(): self
    {
        return $this;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->endpoint, '/'))
            ->acceptJson()
            ->asJson()
            ->withToken($this->apiKey)
            ->timeout($this->timeout);
    }
}
