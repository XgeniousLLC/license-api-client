<?php

namespace Xgenious\XgApiClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Xgenious\XgApiClient\XgApiClient
 * @method static VerifyLicense($purchaseCode,$email,$envatoUsername)
 *
 *
 */
class XgApiClient extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'XgApiClient';
    }
}
