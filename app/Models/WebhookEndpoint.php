<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class WebhookEndpoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'public_id',
        'url',
        'events',
        'status',
        'secret_prefix',
        'signing_secret',
        'last_delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'signing_secret' => 'encrypted',
            'last_delivered_at' => 'datetime',
        ];
    }

    /**
     * @param  array<int, string>  $events
     * @return array{plain_text: string, endpoint: self}
     */
    public static function issue(Project $project, string $url, array $events, string $status = 'active'): array
    {
        $secret = 'whsec_'.Str::random(40);

        return [
            'plain_text' => $secret,
            'endpoint' => self::create([
                'project_id' => $project->id,
                'public_id' => 'wh_'.Str::upper(Str::random(8)),
                'url' => $url,
                'events' => $events,
                'status' => $status,
                'secret_prefix' => Str::substr($secret, 0, 14),
                'signing_secret' => $secret,
            ]),
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function latestDelivery(): HasOne
    {
        return $this->hasOne(WebhookDelivery::class)->latestOfMany('delivered_at');
    }
}
