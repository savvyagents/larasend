<?php

namespace App\Http\Controllers;

use App\Models\Thread;
use App\Models\User;
use App\Support\ProjectContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ThreadActionController extends Controller
{
    public function __construct(private ProjectContext $projectContext) {}

    public function read(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $thread->forceFill(['read_at' => now()])->save();

        return back();
    }

    public function unread(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $thread->forceFill(['read_at' => null])->save();

        return back();
    }

    public function archive(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $thread->forceFill(['archived_at' => now()])->save();

        return back();
    }

    public function unarchive(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $thread->forceFill(['archived_at' => null])->save();

        return back();
    }

    private function authorizeThread(Thread $thread): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        $projectSlug = request()->route('project');
        $project = $this->projectContext->projectFor($user, is_string($projectSlug) ? $projectSlug : null);

        abort_unless($thread->project_id === $project->id, 404);
    }
}
