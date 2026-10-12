<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalTaskAttempt extends Model
{
    protected $table = 'external_task_attempts';

    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'attempt_number',
        'worker_id',
        'credential_id',
        'started_at',
        'sent_at',
        'finished_at',
        'http_status',
        'result_status',
    ];
}
