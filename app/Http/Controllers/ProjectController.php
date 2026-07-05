<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ProjectContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function show(string $project, ProjectContext $context): RedirectResponse
    {
        $selectedProject = $context->projectFor(Auth::user(), $project);

        return redirect($context->sectionPath($selectedProject));
    }

    public function store(StoreProjectRequest $request, ProjectContext $context): RedirectResponse
    {
        $workspace = $context->workspaceFor($request->user());

        abort_unless($this->canManageProjects($workspace, $request->user()), 403);

        $validated = $request->validated();
        $slug = $validated['slug'] ?: Str::slug($validated['name']);

        if ($workspace->projects()->where('slug', $slug)->exists()) {
            return back()->withErrors([
                'slug' => 'A project with this slug already exists in this workspace.',
            ])->withInput();
        }

        $project = $workspace->projects()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'default_environment' => 'prod',
        ]);

        $project->sources()->create([
            'name' => 'Production',
            'environment' => 'prod',
            'ses_region' => 'us-east-1',
            'default_from_name' => $validated['name'],
            'default_from_email' => null,
            'webhook_token' => Str::uuid()->toString(),
        ]);

        session(['current_project_slug' => $project->slug]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Project created. Configure its sending source before production traffic.']);

        return redirect($context->sectionPath($project, 'setup'));
    }

    public function update(UpdateProjectRequest $request, string $project, ProjectContext $context): RedirectResponse
    {
        $workspace = $context->workspaceFor($request->user());
        $projectModel = $this->projectForWorkspace($workspace, $project);

        abort_unless($this->canManageProjects($workspace, $request->user()), 403);
        abort_if($projectModel->archived_at, 422, 'Archived projects cannot be renamed.');

        $validated = $request->validated();
        $slug = $validated['slug'];

        if ($workspace->projects()->where('slug', $slug)->whereKeyNot($projectModel->id)->exists()) {
            return back()->withErrors([
                'slug' => 'A project with this slug already exists in this workspace.',
            ])->withInput();
        }

        $projectModel->update([
            'name' => $validated['name'],
            'slug' => $slug,
        ]);

        session(['current_project_slug' => $projectModel->slug]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Project updated.']);

        return redirect($context->sectionPath($projectModel, 'projects'));
    }

    public function archive(Request $request, string $project, ProjectContext $context): RedirectResponse
    {
        $workspace = $context->workspaceFor($request->user());
        $projectModel = $this->projectForWorkspace($workspace, $project);

        abort_unless($this->canManageProjects($workspace, $request->user()), 403);
        abort_if($projectModel->archived_at, 422, 'Project is already archived.');
        abort_if($workspace->projects()->whereNull('archived_at')->whereKeyNot($projectModel->id)->doesntExist(), 422, 'You must keep at least one active project.');

        $projectModel->forceFill(['archived_at' => now()])->save();

        $fallback = $workspace->projects()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->firstOrFail();

        session(['current_project_slug' => $fallback->slug]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Project archived. Email history remains stored.']);

        return redirect($context->sectionPath($fallback, 'projects'));
    }

    public function restore(Request $request, string $project, ProjectContext $context): RedirectResponse
    {
        $workspace = $context->workspaceFor($request->user());
        $projectModel = $this->projectForWorkspace($workspace, $project);

        abort_unless($this->canManageProjects($workspace, $request->user()), 403);
        abort_unless($projectModel->archived_at, 422, 'Project is already active.');

        $projectModel->forceFill(['archived_at' => null])->save();
        session(['current_project_slug' => $projectModel->slug]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Project restored.']);

        return redirect($context->sectionPath($projectModel, 'projects'));
    }

    public function destroy(Request $request, string $project, ProjectContext $context): RedirectResponse
    {
        $workspace = $context->workspaceFor($request->user());
        $projectModel = $this->projectForWorkspace($workspace, $project);

        abort_unless($this->canManageProjects($workspace, $request->user()), 403);
        abort_if($projectModel->emails()->exists(), 422, 'Projects with email history must be archived instead of deleted.');
        abort_if($projectModel->domains()->exists(), 422, 'Projects with sending domains must be archived instead of deleted.');
        abort_if($workspace->projects()->whereNull('archived_at')->whereKeyNot($projectModel->id)->doesntExist(), 422, 'You must keep at least one active project.');

        $projectModel->delete();

        $fallback = $workspace->projects()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->firstOrFail();

        session(['current_project_slug' => $fallback->slug]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Empty project deleted.']);

        return redirect($context->sectionPath($fallback, 'projects'));
    }

    private function projectForWorkspace(Workspace $workspace, string $project): Project
    {
        return $workspace->projects()
            ->where('slug', $project)
            ->firstOrFail();
    }

    private function canManageProjects(Workspace $workspace, ?User $user): bool
    {
        return $user instanceof User && $workspace->canManageMembers($user);
    }
}
