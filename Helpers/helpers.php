<?php


if (!function_exists('getXgFtpInfoFieldValue')) {
    function getXgFtpInfoFieldValue($field)
    {
        global $fieldName;
        $fieldName = $field;
        $value = \Illuminate\Support\Facades\Cache::remember($fieldName, 86400, function () {
            return \Illuminate\Support\Facades\DB::table('xg_ftp_infos')->first();
        });

        return !empty($value) ? $value->$fieldName : null;
    }
}

if (!function_exists('xg_extension_check')) {
    function extension_check($name)
    {
        if (!extension_loaded($name)) {
            $response = false;
        } else {
            $response = true;
        }
        return $response;
    }
}

if (!function_exists('XGsetEnvValue')) {
    function XGsetEnvValue(array $values)
    {

        $envFile = app()->environmentFilePath();
        $str = file_get_contents($envFile);

        if (count($values) > 0) {
            foreach ($values as $envKey => $envValue) {

                $str .= "\n"; // In case the searched variable is in the last line without \n
                $keyPosition = strpos($str, "{$envKey}=");
                $endOfLinePosition = strpos($str, "\n", $keyPosition);
                $oldLine = substr($str, $keyPosition, $endOfLinePosition - $keyPosition);

                // If key does not exist, add it
                if (!$keyPosition || !$endOfLinePosition || !$oldLine) {
                    $str .= "{$envKey}={$envValue}\n";
                } else {
                    $str = str_replace($oldLine, "{$envKey}={$envValue}", $str);
                }
            }
        }

        $str = substr($str, 0, -1);
        if (!file_put_contents($envFile, $str)) return false;
        return true;
    }
}


if (!function_exists('xgNormalizeBaseApiUrl')) {
    /**
     * Normalize base API URL to ensure it always ends with /api
     * Handles any variation: https://license.xgenious.com, https://license.xgenious.com/api, https://license.xgenious.com/api/
     * 
     * @param string|null $url The base URL to normalize
     * @return string Normalized URL ending with /api (no trailing slash)
     */
    function xgNormalizeBaseApiUrl($url = null)
    {
        // Get from config if not provided
        if ($url === null) {
            $url = \Illuminate\Support\Facades\Config::get('xgapiclient.base_api_url', 'https://license.xgenious.com');
        }
        
        // Remove trailing slashes
        $url = rtrim($url, '/');
        
        // Check if /api is already in the URL
        if (!str_ends_with($url, '/api')) {
            $url .= '/api';
        }
        
        return $url;
    }
}