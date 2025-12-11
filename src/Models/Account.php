<?php

namespace Awesome\Abac\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function getUsersTable()
    {
        return config('awesome-abac.tables.accounts', 'accounts');
    }

    public function users()
    {
        return $this->belongsToMany(
            config('awesome-abac.models.user'),
            config('awesome-abac.tables.account_user', 'account_user'),
            'account_id',
            'user_id'
        );
    }
}
