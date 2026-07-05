<?php

use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ProjectContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function duplicateSourcesFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Acme', 'slug' => 'acme-dup-'.Str::lower(Str::random(6))]);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Proj', 'slug' => 'proj-dup-'.Str::lower(Str::random(6))]);

    $staleId = DB::table('sources')->insertGetId([
        'project_id' => $project->id,
        'name' => 'Stale',
        'environment' => 'prod',
        'ses_region' => 'us-east-1',
        'webhook_token' => (string) Str::uuid(),
        'retention_days' => 90,
        'last_quota_checked_at' => now()->subDays(10),
        'created_at' => now()->subDays(20),
        'updated_at' => now()->subDays(10),
    ]);

    $healthyId = DB::table('sources')->insertGetId([
        'project_id' => $project->id,
        'name' => 'Healthy',
        'environment' => 'prod',
        'ses_region' => 'us-east-1',
        'webhook_token' => (string) Str::uuid(),
        'retention_days' => 90,
        'last_quota_checked_at' => now()->subHour(),
        'created_at' => now()->subDay(),
        'updated_at' => now()->subHour(),
    ]);

    return [$project, $staleId, $healthyId];
}

function runMergeMigration(): void
{
    $migration = include database_path('migrations/2026_07_04_160533_merge_duplicate_sources_and_add_unique_constraint.php');
    $migration->up();
}

it('merges duplicate sources onto the freshest one and reassigns every dependent row', function () {
    Schema::table('sources', fn ($table) => $table->dropUnique(['project_id', 'environment']));

    [$project, $staleId, $healthyId] = duplicateSourcesFixture();

    $apiKey = ApiKey::issue($project, 'Old key', Source::find($staleId))['api_key'];

    $email = Email::create([
        'public_id' => 'email_dup_test',
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $staleId,
        'status' => 'sent',
        'from_email' => 'receipts@example.com',
        'subject' => 'Test',
    ]);

    runMergeMigration();

    expect(Source::query()->whereKey($staleId)->exists())->toBeFalse()
        ->and(Source::query()->whereKey($healthyId)->exists())->toBeTrue()
        ->and($apiKey->fresh()->source_id)->toBe($healthyId)
        ->and($email->fresh()->source_id)->toBe($healthyId)
        ->and(Source::query()->where('project_id', $project->id)->where('environment', 'prod')->count())->toBe(1);
});

it('adding a second source for the same project and environment fails after the migration', function () {
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Acme', 'slug' => 'acme-uniq-'.Str::lower(Str::random(6))]);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Proj', 'slug' => 'proj-uniq-'.Str::lower(Str::random(6))]);

    Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'webhook_token' => (string) Str::uuid(),
    ]);

    expect(fn () => Source::create([
        'project_id' => $project->id,
        'name' => 'Duplicate',
        'environment' => 'prod',
        'webhook_token' => (string) Str::uuid(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('resolves the same source for api key issuance and dashboard display', function () {
    $user = User::factory()->create();
    $context = app(ProjectContext::class);
    $project = $context->projectFor($user);

    $this->actingAs($user)
        ->post('/api-keys', ['name' => 'Production key'])
        ->assertRedirect('/api-keys');

    $issuedKey = ApiKey::query()->where('project_id', $project->id)->latest()->first();
    $canonicalSource = $context->currentSource($project->fresh());

    expect($issuedKey->source_id)->toBe($canonicalSource->id);
});
