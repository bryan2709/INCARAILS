<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalWorkerRuntime extends Model
{
    protected $table = 'external_worker_runtime';

    public $timestamps = true;

    protected $fillable = [
        'worker_id',
        'instance_id',
        'last_heartbeat_at',
        'paused_until',
        'instance_lease_until',
    ];
}
