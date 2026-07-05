<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasFactory;

    /**
     * @var array<string, array<int, string>>
     */
    public const ROLE_CAPABILITIES = [
        'owner' => ['send', 'manage_api_keys', 'manage_domains', 'manage_templates', 'manage_webhooks', 'manage_members'],
        'member' => ['send', 'manage_api_keys', 'manage_domains', 'manage_templates', 'manage_webhooks'],
        'sender' => ['send'],
        'api_keys' => ['manage_api_keys'],
        'domains' => ['manage_domains'],
        'read_only' => [],
    ];

    protected $fillable = [
        'owner_id',
        'name',
        'slug',
        'onboarded_at',
        'setup_started_at',
    ];

    protected function casts(): array
    {
        return [
            'onboarded_at' => 'datetime',
            'setup_started_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function suppressions(): HasMany
    {
        return $this->hasMany(Suppression::class);
    }

    public function roleFor(User $user): ?string
    {
        if ($this->owner_id === $user->id) {
            return 'owner';
        }

        $member = $this->users()
            ->whereKey($user->id)
            ->first();

        return $member?->pivot?->role;
    }

    public function canManageMembers(User $user): bool
    {
        return $this->roleFor($user) === 'owner';
    }

    public function canSendEmail(User $user): bool
    {
        return $this->roleAllows($user, 'send');
    }

    public function canManageApiKeys(User $user): bool
    {
        return $this->roleAllows($user, 'manage_api_keys');
    }

    public function canManageDomains(User $user): bool
    {
        return $this->roleAllows($user, 'manage_domains');
    }

    public function canManageTemplates(User $user): bool
    {
        return $this->roleAllows($user, 'manage_templates');
    }

    public function canManageWebhooks(User $user): bool
    {
        return $this->roleAllows($user, 'manage_webhooks');
    }

    private function roleAllows(User $user, string $capability): bool
    {
        $role = $this->roleFor($user);

        if (! $role) {
            return false;
        }

        return in_array($capability, self::ROLE_CAPABILITIES[$role] ?? [], true);
    }
}
