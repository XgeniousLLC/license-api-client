<?php

namespace Xgenious\XgApiClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Xgenious\XgApiClient\XgApiClient
 * @method static VerifyLicense($purchaseCode,$email,$envatoUsername)
 * @method static activeLicense($licenseCode,$envatoUsername)
 * @method static checkForUpdate($purchaseCode,$getItemVersion)
 * @method static extensionCheck($name)
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
