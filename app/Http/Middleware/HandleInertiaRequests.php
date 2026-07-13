<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\User;
use App\Support\ProjectContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(private ProjectContext $projects) {}

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

        $project = $this->projects->projectFor($user);

        return [
            'project' => [
                'name' => $project->name,
                'slug' => $project->slug,
                'path' => '/projects/'.$project->slug,
            ],
            'projects' => $this->projects->projectsFor($user)->map(fn (Project $workspaceProject): array => [
                'name' => $workspaceProject->name,
                'slug' => $workspaceProject->slug,
                'environment' => $workspaceProject->sources->first()?->environment ?? $workspaceProject->default_environment,
                'provider_label' => $workspaceProject->sources->first()?->provider->label() ?? 'Not connected',
                'is_current' => $workspaceProject->is($project),
                'href' => $this->projects->sectionPath($workspaceProject, 'activity'),
            ])->values(),
            'counts' => [
                'activity' => $project->emails()->count(),
                'inbound' => $project->inboundEmails()->count(),
                'bounces' => $project->emails()->where('status', 'bounced')->count(),
                'complaints' => $project->emails()->where('status', 'complained')->count(),
                'suppressions' => $project->suppressions()->count(),
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
