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

if (!function_exists('extension_check')) {
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
