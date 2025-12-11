<?php

namespace Awesome\Abac\Tests\Integration;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Awesome\Abac\Traits\HasAbac;

class TestUser extends Authenticatable
{
    use HasAbac;
    protected $table = 'users';
    protected $guarded = [];
}
