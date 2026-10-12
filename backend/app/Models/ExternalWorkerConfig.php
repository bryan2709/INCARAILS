<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalWorkerConfig extends Model
{
    protected $table = 'external_worker_configs';

    public $timestamps = true;

    protected $fillable = [
        'worker_key',
        'credential_id',
        'max_requests',
        'window_seconds',
        'cooldown_seconds',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function credential()
    {
        return $this->belongsTo(ExternalCredential::class, 'credential_id');
    }
}
