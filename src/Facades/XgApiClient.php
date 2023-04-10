<?php

namespace Xgenious\XgApiClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Xgenious\XgApiClient\XgApiClient
 */
class XgApiClient extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'xgapiclient';
    }
}
