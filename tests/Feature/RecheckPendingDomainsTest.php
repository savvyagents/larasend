<?php

use App\Jobs\RecheckPendingDomains;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DnsRecordVerifier;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // Domain rechecks dispatched during these tests run inline on the sync
    // queue; stub the DoH resolvers so no real DNS traffic leaves the suite.
    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response(['Status' => 3, 'Answer' => []]),
        'https://dns.google/*' => Http::response(['Status' => 3, 'Answer' => []]),
    ]);
});

function pendingDomainFixture(string $slug, string $status = 'pending'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => $slug, 'slug' => $slug]);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => $slug, 'slug' => $slug]);
    Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'webhook_token' => 'token-'.str()->random(8),
    ]);
    $domain = $project->domains()->create([
        'domain' => "mail.{$slug}.com",
        'status' => $status,
        'dns_records' => [
            ['type' => 'TXT', 'name' => "mail.{$slug}.com", 'value' => 'placeholder', 'status' => 'pending'],
        ],
        'verified_at' => null,
    ]);

    return [$project, $domain];
}

it('verifies pending domains whose dns now resolves', function () {
    [, $domain] = pendingDomainFixture('pending-pass');

    app()->instance(DnsRecordVerifier::class, new class extends DnsRecordVerifier
    {
        public function matches(array $record): bool
        {
            return true;
        }
    });

    (new RecheckPendingDomains)->handle(app(DnsRecordVerifier::class));

    $domain->refresh();

    expect($domain->status)->toBe('verified')
        ->and($domain->verified_at)->not->toBeNull();
});

it('leaves domains pending when dns still does not resolve', function () {
    [, $domain] = pendingDomainFixture('pending-fail');

    (new RecheckPendingDomains)->handle(app(DnsRecordVerifier::class));

    expect($domain->fresh()->status)->toBe('pending');
});

it('does not touch already verified domains', function () {
    [, $domain] = pendingDomainFixture('already-verified', 'verified');

    app()->instance(DnsRecordVerifier::class, new class extends DnsRecordVerifier
    {
        public function matches(array $record): bool
        {
            throw new RuntimeException('should not be called for a verified domain');
        }
    });

    (new RecheckPendingDomains)->handle(app(DnsRecordVerifier::class));

    expect($domain->fresh()->status)->toBe('verified');
});

it('continues checking other domains when one lookup throws', function () {
    [, $failing] = pendingDomainFixture('throws');
    [, $recovers] = pendingDomainFixture('recovers');

    app()->instance(DnsRecordVerifier::class, new class extends DnsRecordVerifier
    {
        public function matches(array $record): bool
        {
            if (str_contains((string) $record['name'], 'throws')) {
                throw new RuntimeException('DNS lookup timed out');
            }

            return true;
        }
    });

    (new RecheckPendingDomains)->handle(app(DnsRecordVerifier::class));

    expect($failing->fresh()->status)->toBe('pending')
        ->and($recovers->fresh()->status)->toBe('verified');
});
