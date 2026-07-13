<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreadUserState extends Model
{
    protected $fillable = ['thread_id', 'user_id', 'read_at', 'last_viewed_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'last_viewed_at' => 'datetime'];
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
