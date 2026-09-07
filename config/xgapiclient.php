<?php
// config for XgApiClient/XgApiClient
return [
    /*
    |--------------------------------------------------------------------------
    | Base API URL
    |--------------------------------------------------------------------------
    |
    | The license server every product talks to. Fixed - not .env-driven -
    | since there is only ever one xgenious license server, and leaving it
    | overridable invites the same class of bug as the update-tuning knobs
    | above (a bad/stale .env value silently pointing licensing at nowhere).
    |
    */
    "base_api_url" => "https://license.xgenious.com",

    /*
    |--------------------------------------------------------------------------
    | Product Token
    |--------------------------------------------------------------------------
    |
    | Unique product code for license server identification.
    |
    */
    "has_token" => env('XG_PRODUCT_TOKEN', ""),

    /*
    |--------------------------------------------------------------------------
    | V2 Update System Configuration
    |--------------------------------------------------------------------------
    |
    | Internal tuning for the chunked update system (V2). These are fixed
    | defaults, not meant to be overridden per-site via .env: they used to
    | read from env() with a numeric fallback, but a numeric-looking env
    | value pasted with a trailing comment (e.g. from a hosting panel's env
    | editor that stores everything after "=" literally, including "#
    | comment" text) silently became a non-numeric string and crashed
    | extraction/replacement with "A non-numeric value encountered". These
    | values are safe and rarely need changing, so the package now owns
    | them directly instead of trusting arbitrary .env content.
    |
    */
    "update" => [
        // Expected chunk size from the server (10MB). Should match the
        // server's chunk size setting.
        "chunk_size" => 10 * 1024 * 1024,

        // Maximum time (seconds) to wait for each chunk download.
        "download_timeout" => 300,

        // Files extracted from the ZIP per batch. Lower uses less memory
        // but takes more requests.
        "extraction_batch_size" => 100,

        // Files replaced per batch. Lower is safer but takes more requests.
        "replacement_batch_size" => 50,

        // Back up original files before replacing them.
        "enable_backup" => true,

        // Analyze composer dependencies and only replace changed vendor
        // packages, to speed up updates.
        "smart_vendor_replacement" => true,

        // Retry attempts for a failed chunk download.
        "max_retries" => 3,

        /*
        |--------------------------------------------------------------------------
        | Status File Location
        |--------------------------------------------------------------------------
        |
        | Where to store the update status JSON file.
        | Must be writable by the web server.
        |
        */
        "status_file" => storage_path('app/xg-update/.update-status.json'),

        /*
        |--------------------------------------------------------------------------
        | Temporary Directory
        |--------------------------------------------------------------------------
        |
        | Directory for storing chunks and extracted files.
        |
        */
        "temp_directory" => storage_path('app/xg-update'),
    ],
];
