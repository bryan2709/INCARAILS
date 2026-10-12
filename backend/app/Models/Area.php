<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $table = 'areas';

    public $timestamps = true;

    protected $fillable = [
        'external_id',
        'name',
        'code',
        'flag_url',
        'parent_external_id',
    ];
}
