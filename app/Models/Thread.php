<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Thread extends Model
{
    protected $fillable = [
        'public_id',
        'workspace_id',
        'project_id',
        'subject',
        'subject_key',
        'participants',
        'last_direction',
        'last_snippet',
        'message_count',
        'last_activity_at',
        'read_at',
        'archived_at',
        'snoozed_until',
        'status',
        'priority',
        'assigned_to_user_id',
        'tags',
    ];

    protected $attributes = ['status' => 'open', 'priority' => 'normal'];

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'last_activity_at' => 'datetime',
            'read_at' => 'datetime',
            'archived_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'tags' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    public function inboundEmails(): HasMany
    {
        return $this->hasMany(InboundEmail::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ThreadNote::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function userStates(): HasMany
    {
        return $this->hasMany(ThreadUserState::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ThreadEvent::class);
    }

    /**
     * Normalize a subject for thread matching: strips reply/forward
     * prefixes and case so "RE: Fwd: Hello" and "hello" fall together.
     */
    public static function subjectKey(?string $subject): string
    {
        $normalized = mb_strtolower(trim((string) $subject));

        while (preg_match('/^(re|fwd?|aw|sv)\s*(\[\d+\])?:\s*/i', $normalized) === 1) {
            $normalized = preg_replace('/^(re|fwd?|aw|sv)\s*(\[\d+\])?:\s*/i', '', $normalized) ?? $normalized;
        }

        return mb_substr($normalized === '' ? '(no subject)' : $normalized, 0, 255);
    }
}
