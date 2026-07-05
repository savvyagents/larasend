<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkspaceMemberRequest;
use App\Http\Requests\UpdateWorkspaceMemberRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ProjectContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;

class WorkspaceMemberController extends Controller
{
    public function store(StoreWorkspaceMemberRequest $request, ProjectContext $context): RedirectResponse
    {
        $workspace = $this->manageableWorkspace($request, $context);
        $validated = $request->validated();
        $member = User::query()
            ->where('email', $validated['email'])
            ->first();
        $created = false;

        if (! $member) {
            $member = User::query()->create([
                'name' => $this->nameFromEmail($validated['email']),
                'email' => $validated['email'],
                'password' => Str::password(32),
            ]);
            $created = true;
        }

        $workspace->users()->syncWithoutDetaching([
            $member->id => ['role' => $validated['role']],
        ]);

        if ($created) {
            Password::broker()->sendResetLink(['email' => $member->email]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $created
                ? 'Workspace member added and sent a password setup link.'
                : 'Workspace member added.',
        ]);

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

    private function manageableWorkspace(Request $request, ProjectContext $context): Workspace
    {
        $user = $request->user();

        abort_unless($user, 403);

        $workspace = $context->workspaceFor($user);

        abort_unless($workspace->canManageMembers($user), 403);

        return $workspace;
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
