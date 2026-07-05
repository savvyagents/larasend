<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'source_id',
        'name',
        'prefix',
        'key_hash',
        'scopes',
        'last_used_at',
        'last_used_ip',
        'last_used_user_agent',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return array{plain_text: string, api_key: self}
     */
    public static function issue(Project $project, string $name, ?Source $source = null, array $scopes = ['send', 'read:activity'], ?\DateTimeInterface $expiresAt = null): array
    {
        $plainText = 'ls_'.Str::random(48);

        return [
            'plain_text' => $plainText,
            'api_key' => self::create([
                'project_id' => $project->id,
                'source_id' => $source?->id,
                'name' => $name,
                'prefix' => Str::substr($plainText, 0, 12),
                'key_hash' => hash('sha256', $plainText),
                'scopes' => array_values(array_unique($scopes)),
                'expires_at' => $expiresAt,
            ]),
        ];
    }

    public function allows(string $scope): bool
    {
        // Keys created before scopes existed have a null column and keep full access.
        // A present-but-empty array is an explicit grant of no scopes, so it denies everything.
        $scopes = $this->scopes ?? ['send', 'read:activity'];

        return in_array($scope, $scopes, true);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
