<?php

use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

function workspaceMembersFixture(): array
{
    $owner = User::factory()->create([
        'name' => 'Alice Owner',
        'email' => 'owner@example.com',
    ]);
    $workspace = Workspace::create([
        'owner_id' => $owner->id,
        'name' => 'Acme',
        'slug' => 'acme-members-'.str()->lower(str()->random(6)),
    ]);
    $workspace->users()->attach($owner, ['role' => 'owner']);
    $project = Project::create([
        'workspace_id' => $workspace->id,
        'name' => 'Billing',
        'slug' => 'billing',
    ]);
    Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'default_from_email' => 'receipts@example.com',
        'webhook_token' => 'members-'.str()->random(8),
    ]);

    return [$owner, $workspace, $project];
}

it('allows workspace owners to add an existing user as a member', function () {
    [$owner, $workspace] = workspaceMembersFixture();
    $member = User::factory()->create(['email' => 'member@example.com']);

    $this->actingAs($owner)
        ->post('/workspace/members', [
            'email' => 'member@example.com',
            'role' => 'member',
        ])
        ->assertRedirect();

    expect($workspace->users()->whereKey($member->id)->first()?->pivot->role)
        ->toBe('member');
});

it('creates a user and sends a setup link when adding a new email', function () {
    Notification::fake();

    [$owner, $workspace] = workspaceMembersFixture();

    $this->actingAs($owner)
        ->post('/workspace/members', [
            'email' => 'krishna@savvyagents.ai',
            'role' => 'member',
        ])
        ->assertRedirect();

    $member = User::where('email', 'krishna@savvyagents.ai')->firstOrFail();

    expect($member->name)->toBe('Krishna')
        ->and($workspace->users()->whereKey($member->id)->first()?->pivot->role)
        ->toBe('member');

    Notification::assertSentTo($member, ResetPassword::class);
});

it('shows workspace members on the projects screen', function () {
    [$owner, $workspace, $project] = workspaceMembersFixture();
    $member = User::factory()->create(['name' => 'Maya Okafor', 'email' => 'maya@example.com']);
    $workspace->users()->attach($member, ['role' => 'member']);

    $this->actingAs($owner)
        ->get("/projects/{$project->slug}/projects")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('workspace.can_manage_members', true)
            ->has('workspaceMembers', 2)
            ->where('workspaceMembers.1.email', 'maya@example.com')
            ->where('workspaceMembers.1.role', 'member')
        );
});

it('allows workspace members to access workspace projects', function () {
    [, $workspace, $project] = workspaceMembersFixture();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    $this->actingAs($member)
        ->get("/projects/{$project->slug}/activity")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('project.slug', 'billing')
            ->where('workspace.can_manage_members', false)
        );
});

it('prevents non owners from managing workspace members', function () {
    [, $workspace] = workspaceMembersFixture();
    $member = User::factory()->create();
    $target = User::factory()->create(['email' => 'target@example.com']);
    $workspace->users()->attach($member, ['role' => 'member']);

    $this->actingAs($member)
        ->post('/workspace/members', [
            'email' => 'target@example.com',
            'role' => 'member',
        ])
        ->assertForbidden();

    expect($workspace->users()->whereKey($target->id)->exists())->toBeFalse();
});

it('allows owners to update and remove workspace members', function () {
    [$owner, $workspace] = workspaceMembersFixture();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    $this->actingAs($owner)
        ->put("/workspace/members/{$member->id}", ['role' => 'owner'])
        ->assertRedirect();

    expect($workspace->users()->whereKey($member->id)->first()?->pivot->role)
        ->toBe('owner');

    $this->actingAs($owner)
        ->delete("/workspace/members/{$member->id}")
        ->assertRedirect();

    expect($workspace->users()->whereKey($member->id)->exists())->toBeFalse();
});

it('does not allow removing the workspace owner', function () {
    [$owner] = workspaceMembersFixture();

    $this->actingAs($owner)
        ->delete("/workspace/members/{$owner->id}")
        ->assertStatus(422);
});
