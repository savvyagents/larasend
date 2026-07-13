<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreadEvent extends Model
{
    protected $fillable = ['thread_id', 'user_id', 'type', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
