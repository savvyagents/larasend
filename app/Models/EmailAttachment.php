<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAttachment extends Model
{
    use HasFactory;

    protected $fillable = ['email_id', 'filename', 'content_type', 'size', 'disposition'];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
