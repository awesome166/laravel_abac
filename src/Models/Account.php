<?php

namespace AbacPermissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Account extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function getUsersTable()
    {
        return config('abacpermissions.tables.accounts', 'accounts');
    }

    public function users()
    {
        return $this->belongsToMany(
            config('abacpermissions.models.user'),
            config('abacpermissions.tables.account_user', 'account_user'),
            'account_id',
            'user_id'
        );
    }
}
