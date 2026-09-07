<?php

namespace Xgenious\XgApiClient\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Xgenious\XgApiClient\XgApiClientServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            XgApiClientServiceProvider::class,
        ];
    }
}
