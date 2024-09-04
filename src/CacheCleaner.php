<?php

namespace Xgenious\XgApiClient;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class CacheCleaner
{

    public static function clearAllCaches()
    {
        // Clear application cache
        Artisan::call('cache:clear');

        // Clear config cache
        Artisan::call('config:clear');

        // Clear route cache
        Artisan::call('route:clear');

        // Clear compiled views
        Artisan::call('view:clear');

        // Clear logs
        (new self)->clearLogs();

        // You can also use optimize:clear to clear multiple caches at once
        // Artisan::call('optimize:clear');
    }

    private function clearLogs()
    {
        $logPath = storage_path('logs');

        if (File::isDirectory($logPath)) {
            File::deleteDirectory($logPath, true);
            File::makeDirectory($logPath, 0755, true, true);
        }
    }

    public static function clearBootstrapCache()
    {
        $cacheDirectory = base_path('bootstrap/cache');
        if (File::isDirectory($cacheDirectory)) { // Check if the directory exists
            $cacheFiles = File::glob($cacheDirectory . '/*.php');

            foreach ($cacheFiles ?? [] as $file) {
                File::delete($file);
            }
        }
    }
}
