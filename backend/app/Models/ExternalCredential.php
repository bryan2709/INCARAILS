<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalCredential extends Model
{
    protected $table = 'external_credentials';

    public $timestamps = true;

    protected $fillable = [
        'alias',
        'secret_reference',
        'quota_pool_id',
        'is_enabled',
        'paused_until',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'paused_until' => 'datetime',
        ];
    }
}
