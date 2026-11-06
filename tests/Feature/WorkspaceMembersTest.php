<?php

use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ControlEmailVerification;
use App\Notifications\ControlPasswordReset;
use App\Support\ProjectContext;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
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
    config(['larasend.control_mailer' => 'smtp']);

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

    expect($member->email_verification_required_at)->not->toBeNull();
    Notification::assertSentTo($member, ControlEmailVerification::class);
    Notification::assertSentTo($member, ControlPasswordReset::class);
    expect($member->fresh()->workspace_invitation_pending_at)->toBeNull();
});

it('blocks invitations that would create an unreachable user without control mail', function () {
    [$owner, $workspace] = workspaceMembersFixture();

    $this->actingAs($owner)
        ->post('/workspace/members', [
            'email' => 'unreachable@example.com',
            'role' => 'member',
        ])
        ->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'unreachable@example.com')->exists())->toBeFalse()
        ->and($workspace->users()->where('email', 'unreachable@example.com')->exists())->toBeFalse();
});

it('retries an invitation whose initial notification dispatch failed', function () {
    config(['larasend.control_mailer' => 'smtp']);
    [$owner, $workspace] = workspaceMembersFixture();

    $notifications = app(NotificationDispatcher::class);
    $failingNotifications = Mockery::mock(NotificationDispatcher::class);
    $failingNotifications
        ->shouldReceive('send')
        ->once()
        ->andThrow(new RuntimeException('The queue is unavailable.'));
    $this->app->instance(NotificationDispatcher::class, $failingNotifications);

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)->post('/workspace/members', [
        'email' => 'pending@example.com',
        'role' => 'member',
    ]))->toThrow(RuntimeException::class);

    $retryNotifications = Mockery::mock(NotificationDispatcher::class);
    $retryNotifications->shouldReceive('send')->twice()->andReturnNull();
    $this->app->instance(NotificationDispatcher::class, $retryNotifications);
    $member = User::query()->where('email', 'pending@example.com')->firstOrFail();

    $this->actingAs($owner)
        ->post('/workspace/members', [
            'email' => $member->email,
            'role' => 'member',
        ])
        ->assertRedirect();

    expect($member->fresh()->workspace_invitation_pending_at)->toBeNull();

    $this->app->instance(NotificationDispatcher::class, $notifications);
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
        ->put("/workspace/members/{$member->id}", ['role' => 'sender'])
        ->assertRedirect();

    expect($workspace->users()->whereKey($member->id)->first()?->pivot->role)
        ->toBe('sender');

    $this->actingAs($owner)
        ->delete("/workspace/members/{$member->id}")
        ->assertRedirect();

    expect($workspace->users()->whereKey($member->id)->exists())->toBeFalse();
});

it('keeps a user in their own workspace context when added to a lower-id workspace', function () {
    [, $foreignWorkspace] = workspaceMembersFixture();
    $victim = User::factory()->create(['email' => 'victim@example.com']);
    $ownWorkspace = app(ProjectContext::class)->workspaceFor($victim);

    $foreignWorkspace->users()->attach($victim, ['role' => 'member']);

    expect($foreignWorkspace->id)->toBeLessThan($ownWorkspace->id)
        ->and(app(ProjectContext::class)->workspaceFor($victim)->is($ownWorkspace))->toBeTrue();
});

it('still resolves a membership workspace for users who own none', function () {
    [, $workspace] = workspaceMembersFixture();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    expect(app(ProjectContext::class)->workspaceFor($member)->is($workspace))->toBeTrue();
});

it('allows members to leave a workspace they do not own', function () {
    [, $workspace] = workspaceMembersFixture();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    $this->actingAs($member)
        ->delete("/workspace/{$workspace->id}/membership")
        ->assertRedirect('/activity');

    expect($workspace->users()->whereKey($member->id)->exists())->toBeFalse();
});

it('does not allow the owner to leave their own workspace', function () {
    [$owner, $workspace] = workspaceMembersFixture();

    $this->actingAs($owner)
        ->delete("/workspace/{$workspace->id}/membership")
        ->assertStatus(422);

    expect($workspace->users()->whereKey($owner->id)->exists())->toBeTrue();
});

it('returns not found when leaving a workspace without a membership', function () {
    [, $workspace] = workspaceMembersFixture();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->delete("/workspace/{$workspace->id}/membership")
        ->assertNotFound();
});

it('does not allow removing the workspace owner', function () {
    [$owner] = workspaceMembersFixture();

    $this->actingAs($owner)
        ->delete("/workspace/members/{$owner->id}")
        ->assertStatus(422);
});

it('transfers workspace ownership only to an existing workspace member', function () {
    [$owner, $workspace] = workspaceMembersFixture();
    $newOwner = User::factory()->create(['email' => 'new-owner@example.com']);
    $workspace->users()->attach($newOwner, ['role' => 'member']);

    $this->actingAs($owner)
        ->patch("/workspace/members/{$newOwner->id}/ownership")
        ->assertRedirect();

    expect($workspace->fresh()->owner_id)->toBe($newOwner->id)
        ->and($workspace->users()->whereKey($newOwner->id)->first()?->pivot->role)->toBe('owner')
        ->and($workspace->users()->whereKey($owner->id)->first()?->pivot->role)->toBe('member');
});

it('forbids non-owners from transferring workspace ownership', function () {
    [$owner, $workspace] = workspaceMembersFixture();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    $this->actingAs($member)
        ->patch("/workspace/members/{$owner->id}/ownership")
        ->assertForbidden();

    expect($workspace->fresh()->owner_id)->toBe($owner->id);
});

it('does not transfer workspace ownership to a user outside the workspace', function () {
    [$owner, $workspace] = workspaceMembersFixture();
    $outsider = User::factory()->create();

    $this->actingAs($owner)
        ->patch("/workspace/members/{$outsider->id}/ownership")
        ->assertNotFound();

    expect($workspace->fresh()->owner_id)->toBe($owner->id);
});

it('does not treat owner as an assignable member role', function () {
    [$owner, $workspace] = workspaceMembersFixture();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    $this->actingAs($owner)
        ->put("/workspace/members/{$member->id}", ['role' => 'owner'])
        ->assertSessionHasErrors('role');

    expect($workspace->fresh()->owner_id)->toBe($owner->id)
        ->and($workspace->users()->whereKey($member->id)->first()?->pivot->role)->toBe('member');
});
