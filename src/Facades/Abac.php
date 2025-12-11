<?php

namespace Awesome\Abac\Facades;

use Illuminate\Support\Facades\Facade;

class Abac extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'awesome.abac';
    }
}
