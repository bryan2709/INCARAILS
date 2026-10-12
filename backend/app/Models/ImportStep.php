<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportStep extends Model
{
    protected $table = 'import_steps';

    public $timestamps = true;

    protected $fillable = [
        'import_id',
        'step_key',
        'status',
        'completed_at',
        'error_code',
    ];

    public function import()
    {
        return $this->belongsTo(Import::class);
    }
}
