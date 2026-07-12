<?php

use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;

function projectSwitchingFixture(): array
{
    $user = User::factory()->create();
    $suffix = str()->lower(str()->random(6));
    $workspace = Workspace::create([
        'owner_id' => $user->id,
        'name' => 'Acme',
        'slug' => 'acme-switching-'.$suffix,
    ]);
    $workspace->users()->attach($user, ['role' => 'owner']);

    $primary = Project::create([
        'workspace_id' => $workspace->id,
        'name' => 'Harborlight',
        'slug' => 'harborlight',
    ]);
    $secondary = Project::create([
        'workspace_id' => $workspace->id,
        'name' => 'Northwind',
        'slug' => 'northwind',
    ]);

    $primarySource = Source::create([
        'project_id' => $primary->id,
        'name' => 'Production',
        'environment' => 'prod',
        'default_from_email' => 'receipts@harborlight.test',
        'webhook_token' => 'harborlight-'.str()->random(8),
    ]);
    $secondarySource = Source::create([
        'project_id' => $secondary->id,
        'name' => 'Production',
        'environment' => 'prod',
        'default_from_email' => 'receipts@northwind.test',
        'webhook_token' => 'northwind-'.str()->random(8),
    ]);

    Email::create([
        'public_id' => 'email_harborlight_'.$suffix,
        'workspace_id' => $workspace->id,
        'project_id' => $primary->id,
        'source_id' => $primarySource->id,
        'status' => 'sent',
        'from_email' => 'receipts@harborlight.test',
        'subject' => 'Harborlight receipt',
        'created_at' => now(),
        'updated_at' => now(),
        'sent_at' => now(),
    ])->recipients()->create(['type' => 'to', 'email' => 'maya@harborlight.test', 'name' => 'Maya Harbor']);

    Email::create([
        'public_id' => 'email_northwind_'.$suffix,
        'workspace_id' => $workspace->id,
        'project_id' => $secondary->id,
        'source_id' => $secondarySource->id,
        'status' => 'sent',
        'from_email' => 'receipts@northwind.test',
        'subject' => 'Northwind receipt',
        'created_at' => now(),
        'updated_at' => now(),
        'sent_at' => now(),
    ])->recipients()->create(['type' => 'to', 'email' => 'maya@northwind.test', 'name' => 'Maya North']);

    return [$user, $primary, $secondary];
}

it('renders project switcher data and scopes activity by selected project slug', function () {
    [$user, $primary, $secondary] = projectSwitchingFixture();
    $primaryEmail = $primary->emails()->firstOrFail();
    $secondaryEmail = $secondary->emails()->firstOrFail();

    $this->actingAs($user)
        ->get("/projects/{$secondary->slug}/activity")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('project.slug', 'northwind')
            ->has('projects', 2)
            ->where('projects.1.slug', 'northwind')
            ->where('projects.1.is_current', true)
            ->has('emails', 1)
            ->where('emails.0.id', $secondaryEmail->public_id)
        );

    $this->actingAs($user)
        ->get("/projects/{$primary->slug}/activity")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('project.slug', 'harborlight')
            ->has('emails', 1)
            ->where('emails.0.id', $primaryEmail->public_id)
        );
});

it('creates a project and redirects to its setup page', function () {
    [$user] = projectSwitchingFixture();

    $this->actingAs($user)
        ->post('/projects', [
            'name' => 'Billing Platform',
            'slug' => 'billing-platform',
        ])
        ->assertRedirect('/projects/billing-platform/setup');

    $project = $user->workspaces()->first()->projects()->where('slug', 'billing-platform')->firstOrFail();

    expect($project->name)->toBe('Billing Platform')
        ->and($project->sources()->where('environment', 'prod')->exists())->toBeTrue();
});

it('allows workspace owners to rename projects', function () {
    [$user, $primary] = projectSwitchingFixture();

    $this->actingAs($user)
        ->put("/projects/{$primary->slug}", [
            'name' => 'Harborlight Mail',
            'slug' => 'harborlight-mail',
        ])
        ->assertRedirect('/projects/harborlight-mail/projects');

    expect($primary->fresh())
        ->name->toBe('Harborlight Mail')
        ->slug->toBe('harborlight-mail');
});

it('archives active projects without deleting email history', function () {
    [$user, $primary, $secondary] = projectSwitchingFixture();

    $this->actingAs($user)
        ->post("/projects/{$secondary->slug}/archive")
        ->assertRedirect('/projects/harborlight/projects');

    expect($secondary->fresh()->archived_at)->not->toBeNull()
        ->and($secondary->emails()->exists())->toBeTrue();

    $this->actingAs($user)
        ->get("/projects/{$secondary->slug}/activity")
        ->assertNotFound();

    $this->actingAs($user)
        ->get("/projects/{$primary->slug}/projects")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('projects', 1)
            ->where('projects.0.slug', 'harborlight')
        );
});

it('restores archived projects to active navigation', function () {
    [$user, $primary, $secondary] = projectSwitchingFixture();

    $secondary->forceFill(['archived_at' => now()])->save();

    $this->actingAs($user)
        ->post("/projects/{$secondary->slug}/restore")
        ->assertRedirect("/projects/{$secondary->slug}/projects");

    expect($secondary->fresh()->archived_at)->toBeNull();

    $this->actingAs($user)
        ->get("/projects/{$secondary->slug}/projects")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('project.slug', 'northwind')
            ->has('projects', 2)
        );

    expect($primary)->toBeInstanceOf(Project::class);
});

it('deletes empty projects but keeps projects with delivery history archivable only', function () {
    [$user, $primary] = projectSwitchingFixture();

    $empty = Project::create([
        'workspace_id' => $primary->workspace_id,
        'name' => 'Empty',
        'slug' => 'empty',
    ]);

    $this->actingAs($user)
        ->delete("/projects/{$empty->slug}")
        ->assertRedirect('/projects/harborlight/projects');

    expect(Project::query()->whereKey($empty->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->delete("/projects/{$primary->slug}")
        ->assertStatus(422);

    expect($primary->fresh())->not->toBeNull();
});

it('uses the selected project for dashboard actions', function () {
    [$user, $primary, $secondary] = projectSwitchingFixture();

    $this->actingAs($user)
        ->post("/projects/{$secondary->slug}/api-keys", ['name' => 'Northwind key'])
        ->assertRedirect("/projects/{$secondary->slug}/api-keys")
        ->assertSessionHas('newApiKey');

    expect($secondary->apiKeys()->where('name', 'Northwind key')->exists())->toBeTrue()
        ->and($primary->apiKeys()->where('name', 'Northwind key')->exists())->toBeFalse();
});

it('keeps configuration pages scoped to the selected project', function () {
    [$user, , $secondary] = projectSwitchingFixture();

    foreach (['setup', 'source', 'identities', 'templates', 'webhooks', 'api-keys', 'projects'] as $section) {
        $this->actingAs($user)
            ->get("/projects/{$secondary->slug}/{$section}")
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('Activity')
                ->where('project.slug', 'northwind')
                ->where('section', $section)
            );
    }
});

it('uses the last selected project when legacy dashboard links are visited', function () {
    [$user, , $secondary] = projectSwitchingFixture();

    $this->actingAs($user)
        ->get("/projects/{$secondary->slug}/activity")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('project.slug', 'northwind'));

    $this->actingAs($user)
        ->get('/identities')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('project.slug', 'northwind')
            ->where('section', 'identities')
        );
});

it('does not allow switching to projects outside the user workspace', function () {
    [$user] = projectSwitchingFixture();
    [$otherUser, , $otherProject] = projectSwitchingFixture();
    $otherProject->forceFill(['slug' => 'external-project'])->save();

    $this->actingAs($user)
        ->get("/projects/{$otherProject->slug}/activity")
        ->assertNotFound();

    expect($otherUser)->toBeInstanceOf(User::class);
});
