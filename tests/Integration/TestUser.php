<?php

namespace AbacPermissions\Tests\Integration;

use Illuminate\Foundation\Auth\User as Authenticatable;
use AbacPermissions\Traits\HasAbac;

class TestUser extends Authenticatable
{
    use HasAbac;
    protected $table = 'users';
    protected $guarded = [];
}
