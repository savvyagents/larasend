<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkspaceMemberRequest;
use App\Http\Requests\TransferWorkspaceOwnershipRequest;
use App\Http\Requests\UpdateWorkspaceMemberRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ControlMail;
use App\Support\ProjectContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

class WorkspaceMemberController extends Controller
{
    public function __construct(private ControlMail $controlMail) {}

    public function store(StoreWorkspaceMemberRequest $request, ProjectContext $context): RedirectResponse
    {
        $workspace = $this->manageableWorkspace($request, $context);
        $validated = $request->validated();
        $member = $this->addMember($workspace, $validated['email'], $validated['role']);
        $invitationDelivered = $this->deliverPendingInvitation($member);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $invitationDelivered
                ? 'Workspace member added and sent a password setup link.'
                : 'Workspace member added.',
        ]);

        return back();
    }

    public function transferOwnership(TransferWorkspaceOwnershipRequest $request, User $user, ProjectContext $context): RedirectResponse
    {
        $workspace = $this->manageableWorkspace($request, $context);
        $currentOwner = $request->user();

        abort_unless($currentOwner instanceof User, 403);
        abort_if($workspace->owner_id === $user->id, 422, 'This user already owns the workspace.');
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);

        DB::transaction(function () use ($workspace, $currentOwner, $user): void {
            $workspace->forceFill(['owner_id' => $user->id])->save();
            $workspace->users()->updateExistingPivot($user->id, ['role' => 'owner']);
            $workspace->users()->updateExistingPivot($currentOwner->id, ['role' => 'member']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Workspace ownership transferred.']);

        return back();
    }

    public function update(UpdateWorkspaceMemberRequest $request, User $user, ProjectContext $context): RedirectResponse
    {
        $workspace = $this->manageableWorkspace($request, $context);

        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);
        abort_if($workspace->owner_id === $user->id, 422, 'The workspace owner role cannot be changed.');

        $workspace->users()->updateExistingPivot($user->id, [
            'role' => $request->validated('role'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Workspace member updated.']);

        return back();
    }

    public function destroy(Request $request, User $user, ProjectContext $context): RedirectResponse
    {
        $workspace = $this->manageableWorkspace($request, $context);

        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);
        abort_if($workspace->owner_id === $user->id, 422, 'The workspace owner cannot be removed.');
        abort_if($request->user()?->is($user), 422, 'You cannot remove yourself from the workspace.');

        $workspace->users()->detach($user->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Workspace member removed.']);

        return back();
    }

    /**
     * Let a user remove themselves from a workspace they do not own, so being
     * added to someone else's workspace is never a membership they cannot undo.
     */
    public function leave(Request $request, Workspace $workspace): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);
        abort_if($workspace->owner_id === $user->id, 422, 'The workspace owner cannot leave the workspace.');

        $workspace->users()->detach($user->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'You left the workspace.']);

        return redirect('/activity');
    }

    private function manageableWorkspace(Request $request, ProjectContext $context): Workspace
    {
        $user = $request->user();

        abort_unless($user, 403);

        $workspace = $context->workspaceFor($user);

        abort_unless($workspace->canManageMembers($user), 403);

        return $workspace;
    }

    private function addMember(Workspace $workspace, string $email, string $role): User
    {
        return DB::transaction(function () use ($workspace, $email, $role): User {
            $member = User::query()->where('email', $email)->first();

            if (! $member) {
                if (! $this->controlMail->isConfigured()) {
                    throw ValidationException::withMessages([
                        'email' => 'Configure a dedicated control email mailer before inviting a new user.',
                    ]);
                }

                $member = new User;
                $member->fill([
                    'name' => $this->nameFromEmail($email),
                    'email' => $email,
                    'password' => Str::password(32),
                ]);
                $member->forceFill([
                    ...$this->controlMail->verificationAttributes(),
                    'workspace_invitation_pending_at' => now(),
                ])->save();
            }

            $workspace->users()->syncWithoutDetaching([
                $member->id => ['role' => $role],
            ]);

            return $member;
        });
    }

    private function deliverPendingInvitation(User $member): bool
    {
        if ($member->workspace_invitation_pending_at === null) {
            return false;
        }

        event(new Registered($member));

        $status = Password::broker()->sendResetLink(['email' => $member->email]);

        if ($status !== PasswordBroker::RESET_LINK_SENT) {
            throw new RuntimeException('The password setup link could not be queued. Retry the invitation.');
        }

        $member->forceFill(['workspace_invitation_pending_at' => null])->save();

        return true;
    }

    private function nameFromEmail(string $email): string
    {
        return Str::of($email)
            ->before('@')
            ->replace(['.', '_', '-'], ' ')
            ->headline()
            ->toString();
    }
}
