<?php

namespace AbacPermissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class ActivityLog extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
    ];

    public function causer()
    {
        return $this->morphTo();
    }

    public function subject()
    {
        return $this->morphTo();
    }
}
