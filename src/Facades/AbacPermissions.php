<?php

namespace AbacPermissions\Facades;

use Illuminate\Support\Facades\Facade;

class AbacPermissions extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'abacpermissions';
    }
}
