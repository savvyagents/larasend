<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundEmail extends Model
{
    protected $fillable = [
        'public_id',
        'workspace_id',
        'project_id',
        'source_id',
        'from_email',
        'from_name',
        'to_email',
        'subject',
        'text',
        'html',
        'headers',
        'attachments',
        'message_id',
        'in_reply_to',
        'mime_disk',
        'mime_path',
        'mime_size',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'attachments' => 'array',
            'received_at' => 'datetime',
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

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
