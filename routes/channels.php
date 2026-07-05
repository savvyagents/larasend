<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('projects.{projectId}.{environment}.activity', function (User $user, int $projectId): bool {
    return $user->workspaces()
        ->whereHas('projects', fn ($query) => $query->whereKey($projectId))
        ->exists();
});
