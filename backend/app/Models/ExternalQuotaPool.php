<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalQuotaPool extends Model
{
    protected $table = 'external_quota_pools';

    public $timestamps = true;

    protected $fillable = [
        'provider',
        'name',
        'max_requests',
        'window_seconds',
        'cooldown_until',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'cooldown_until' => 'datetime',
        ];
    }
}
