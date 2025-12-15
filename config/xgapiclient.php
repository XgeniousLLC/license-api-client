<?php
// config for XgApiClient/XgApiClient
return [
    /*
    |--------------------------------------------------------------------------
    | Base API URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the license server API.
    |
    */
    "base_api_url" => env('XG_LICENSE_API_URL', "https://license.xgenious.com"),

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
    | Settings for the chunked update system (V2).
    |
    */
    "update" => [
        /*
        |--------------------------------------------------------------------------
        | Chunk Size (bytes)
        |--------------------------------------------------------------------------
        |
        | Expected chunk size from the server (10MB default).
        | This should match the server's chunk size setting.
        |
        */
        "chunk_size" => env('XG_UPDATE_CHUNK_SIZE', 10 * 1024 * 1024),

        /*
        |--------------------------------------------------------------------------
        | Download Timeout (seconds)
        |--------------------------------------------------------------------------
        |
        | Maximum time to wait for each chunk download.
        |
        */
        "download_timeout" => env('XG_UPDATE_DOWNLOAD_TIMEOUT', 300),

        /*
        |--------------------------------------------------------------------------
        | Extraction Batch Size
        |--------------------------------------------------------------------------
        |
        | Number of files to extract from ZIP in each batch.
        | Lower values use less memory but take more requests.
        |
        */
        "extraction_batch_size" => env('XG_UPDATE_EXTRACTION_BATCH', 100),

        /*
        |--------------------------------------------------------------------------
        | Replacement Batch Size
        |--------------------------------------------------------------------------
        |
        | Number of files to replace in each batch.
        | Lower values are safer but take more requests.
        |
        */
        "replacement_batch_size" => env('XG_UPDATE_REPLACEMENT_BATCH', 50),

        /*
        |--------------------------------------------------------------------------
        | Enable File Backup
        |--------------------------------------------------------------------------
        |
        | Whether to backup original files before replacing.
        | Recommended for production but increases update time and storage.
        |
        */
        "enable_backup" => env('XG_UPDATE_ENABLE_BACKUP', true),

        /*
        |--------------------------------------------------------------------------
        | Smart Vendor Replacement
        |--------------------------------------------------------------------------
        |
        | Whether to analyze composer dependencies and only replace changed
        | vendor packages. This can significantly speed up updates.
        |
        */
        "smart_vendor_replacement" => env('XG_UPDATE_SMART_VENDOR', true),

        /*
        |--------------------------------------------------------------------------
        | Max Retry Attempts
        |--------------------------------------------------------------------------
        |
        | Maximum number of retry attempts for failed chunk downloads.
        |
        */
        "max_retries" => env('XG_UPDATE_MAX_RETRIES', 3),

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
