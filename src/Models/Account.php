<?php

namespace AbacPermissions\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
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
