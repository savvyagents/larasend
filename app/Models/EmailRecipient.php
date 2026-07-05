<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailRecipient extends Model
{
    use HasFactory;

    protected $fillable = ['email_id', 'type', 'email', 'name'];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
