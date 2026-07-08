<?php

namespace App\Support;

use App\Enums\SourceProvider;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ProjectContext
{
    public function workspaceFor(User $user): Workspace
    {
        $workspace = $user->workspaces()->orderBy('workspaces.id')->first();

        if ($workspace) {
            return $workspace;
        }

        $workspace = Workspace::query()->create([
            'owner_id' => $user->id,
            'name' => $user->name."'s Workspace",
            'slug' => Str::slug($user->name).'-'.Str::lower(Str::random(6)),
        ]);

        $workspace->users()->attach($user, ['role' => 'owner']);

        return $workspace;
    }

    public function projectFor(User $user, ?string $projectSlug = null): Project
    {
        $workspace = $this->workspaceFor($user);

        if ($projectSlug) {
            $project = $workspace->projects()
                ->where('slug', $projectSlug)
                ->whereNull('archived_at')
                ->first();

            abort_unless($project, 404);

            session(['current_project_slug' => $project->slug]);

            return $this->ensureSource($project);
        }

        $sessionProjectSlug = session('current_project_slug');

        if (is_string($sessionProjectSlug) && $sessionProjectSlug !== '') {
            $project = $workspace->projects()
                ->where('slug', $sessionProjectSlug)
                ->whereNull('archived_at')
                ->first();

            if ($project) {
                return $this->ensureSource($project);
            }
        }

        $project = $workspace->projects()
            ->whereNull('archived_at')
            ->orderBy('id')
            ->first();

        if (! $project) {
            $project = $workspace->projects()->create([
                'name' => 'My Project',
                'slug' => 'my-project',
                'default_environment' => 'prod',
            ]);
        }

        session(['current_project_slug' => $project->slug]);

        return $this->ensureSource($project);
    }

    /**
     * @return Collection<int, Project>
     */
    public function projectsFor(User $user): Collection
    {
        $workspace = $this->workspaceFor($user);

        return $workspace->projects()
            ->with('sources')
            ->withCount(['emails', 'domains'])
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Project>
     */
    public function archivedProjectsFor(User $user): Collection
    {
        $workspace = $this->workspaceFor($user);

        return $workspace->projects()
            ->with('sources')
            ->withCount(['emails', 'domains'])
            ->whereNotNull('archived_at')
            ->orderByDesc('archived_at')
            ->get();
    }

    public function sectionPath(Project $project, string $section = 'activity'): string
    {
        return '/projects/'.$project->slug.'/'.$section;
    }

    public function exportPath(Project $project): string
    {
        return '/projects/'.$project->slug.'/activity/export';
    }

    /**
     * The single canonical source for a project. This is the only place in
     * the app that should decide "which source" — every controller reads or
     * writes through here so the dashboard, API key issuance, and sending
     * can never silently disagree about which source is current.
     */
    public function currentSource(Project $project): Source
    {
        $sources = $project->sources;

        if ($sources->isEmpty()) {
            return $project->sources()->create([
                'name' => 'Production',
                'environment' => $project->default_environment,
                'provider' => SourceProvider::Ses,
                'ses_region' => 'us-east-1',
                'default_from_name' => 'Larasend',
                'default_from_email' => null,
                'webhook_token' => Str::uuid()->toString(),
            ]);
        }

        if ($sources->count() === 1) {
            return $sources->first();
        }

        // Legacy duplicate rows (pre-unique-constraint) may still linger.
        // Prefer whichever matches the project's current environment, then
        // fall back to the most recently quota-synced row.
        return $sources->firstWhere('environment', $project->default_environment)
            ?? $sources->sortByDesc('last_quota_checked_at')->first();
    }

    private function ensureSource(Project $project): Project
    {
        $this->currentSource($project);

        return $project->fresh(['sources']) ?? $project;
    }
}
