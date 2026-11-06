<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\User;
use App\Support\ControlMail;
use App\Support\ProjectContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private ProjectContext $projects,
        private ControlMail $controlMail,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'build' => [
                'version' => config('app.version'),
                'sha' => config('app.git_sha'),
            ],
            'controlMail' => [
                'configured' => $this->controlMail->isConfigured(),
            ],
            'settingsNavigation' => fn (): ?array => $this->settingsNavigation($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function settingsNavigation(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->routeIs('profile.*', 'security.*', 'appearance.*')) {
            return null;
        }

        $workspace = $user->workspaces()->where('workspaces.owner_id', $user->id)->orderBy('workspaces.id')->first()
            ?? $user->workspaces()->orderBy('workspaces.id')->first();

        if (! $workspace) {
            return null;
        }

        $projects = $workspace->projects()
            ->with('sources')
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();
        $currentProjectSlug = session('current_project_slug');
        $project = is_string($currentProjectSlug)
            ? $projects->firstWhere('slug', $currentProjectSlug)
            : null;
        $project ??= $projects->first();

        if (! $project) {
            return null;
        }

        $projectCounts = Project::query()
            ->whereKey($project->id)
            ->withCount([
                'emails as activity_count',
                'inboundEmails as inbound_count',
                'emails as bounces_count' => fn ($query) => $query->where('status', 'bounced'),
                'emails as complaints_count' => fn ($query) => $query->where('status', 'complained'),
                'suppressions as suppressions_count' => fn ($query) => $query->active(),
            ])
            ->firstOrFail();

        return [
            'project' => [
                'name' => $project->name,
                'slug' => $project->slug,
                'path' => '/projects/'.$project->slug,
            ],
            'projects' => $projects->map(fn (Project $workspaceProject): array => [
                'name' => $workspaceProject->name,
                'slug' => $workspaceProject->slug,
                'environment' => $workspaceProject->sources->first()?->environment ?? $workspaceProject->default_environment,
                'provider_label' => $workspaceProject->sources->first()?->provider->label() ?? 'Not connected',
                'is_current' => $workspaceProject->is($project),
                'href' => $this->projects->sectionPath($workspaceProject, 'activity'),
            ])->values(),
            'counts' => [
                'activity' => $projectCounts->activity_count,
                'inbound' => $projectCounts->inbound_count,
                'bounces' => $projectCounts->bounces_count,
                'complaints' => $projectCounts->complaints_count,
                'suppressions' => $projectCounts->suppressions_count,
            ],
            'inbox_unread' => $project->threads()
                ->whereNull('archived_at')
                ->where('status', '!=', 'closed')
                ->where(fn ($query) => $query
                    ->whereNull('snoozed_until')
                    ->orWhere('snoozed_until', '<=', now()))
                ->whereDoesntHave('userStates', fn ($query) => $query
                    ->where('user_id', $user->id)
                    ->whereNotNull('read_at'))
                ->count(),
        ];
    }
}
