<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'domain_id',
        'name',
        'environment',
        'ses_region',
        'ses_configuration_set',
        'default_from_name',
        'default_from_email',
        'aws_access_key_id',
        'aws_secret_access_key',
        'aws_session_token',
        'webhook_token',
        'retention_days',
        'monthly_quota',
        'max_send_rate',
        'last_quota_checked_at',
        'last_quota',
    ];

    /**
     * Defense in depth: these must never reach toArray()/toJson()/Inertia props,
     * even if a future change serializes the whole model instead of an allow-listed array.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'aws_access_key_id',
        'aws_secret_access_key',
        'aws_session_token',
        'webhook_token',
    ];

    protected function casts(): array
    {
        return [
            'aws_access_key_id' => 'encrypted',
            'aws_secret_access_key' => 'encrypted',
            'aws_session_token' => 'encrypted',
            'last_quota_checked_at' => 'datetime',
            'last_quota' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }
}
