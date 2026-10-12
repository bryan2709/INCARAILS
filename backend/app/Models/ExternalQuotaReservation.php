<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalQuotaReservation extends Model
{
    protected $table = 'external_quota_reservations';

    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'attempt_id',
        'worker_id',
        'quota_pool_id',
        'reserved_at',
        'reservation_status',
    ];
}
