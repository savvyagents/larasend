<?php

namespace Larasend\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Larasend\Laravel\LarasendClient;

class Larasend extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LarasendClient::class;
    }
}
