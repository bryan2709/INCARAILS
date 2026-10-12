<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalTask extends Model
{
    protected $table = 'external_tasks';

    public $timestamps = true;

    protected $fillable = [
        'import_step_id',
        'request_key',
        'resource',
        'status',
        'assigned_worker_id',
        'lease_version',
        'lease_until',
        'not_before',
        'retry_of_task_id',
        'response_json',
    ];

    protected function casts(): array
    {
        return [
            'response_json' => 'array',
            'lease_version' => 'integer',
            'lease_until' => 'datetime',
            'not_before' => 'datetime',
        ];
    }

    public function step()
    {
        return $this->belongsTo(ImportStep::class, 'import_step_id');
    }

    public function attempts()
    {
        return $this->hasMany(ExternalTaskAttempt::class, 'task_id');
    }
}
