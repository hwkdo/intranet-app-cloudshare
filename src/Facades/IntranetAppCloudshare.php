<?php

namespace Hwkdo\IntranetAppCloudshare\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Hwkdo\IntranetAppCloudshare\IntranetAppCloudshare
 */
class IntranetAppCloudshare extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hwkdo\IntranetAppCloudshare\IntranetAppCloudshare::class;
    }
}
