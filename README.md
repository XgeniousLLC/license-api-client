# this package is for managing the license management system for xgenious internals

[![Latest Version on Packagist](https://img.shields.io/packagist/v/xgenious/xgapiclient.svg?style=flat-square)](https://packagist.org/packages/xgenious/xgapiclient)
[![Total Downloads](https://img.shields.io/packagist/dt/xgenious/xgapiclient.svg?style=flat-square)](https://packagist.org/packages/xgenious/xgapiclient)


## Installation

You can install the package via composer:

```bash
composer require xgenious/xgapiclient
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="xgapiclient-migrations" 
php artisan migrate
```

You can publish the config file with:
```bash
php artisan vendor:publish --tag="xgapiclient-config"
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="xgapiclient-views"
```

### V2 Chunked Update System Installation

For the new V2 chunked update system, you need to publish the JavaScript assets:

```bash
php artisan vendor:publish --tag=xgapiclient-assets
```

This publishes `UpdateManager.js` to `assets/vendor/xgapiclient/js/`.

Ensure the update storage directory exists:

```bash
mkdir -p storage/app/xg-update
chmod 755 storage/app/xg-update
```

### Configuration

This is the contents of the published config file (`config/xgapiclient.php`):

```php
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
    | Recommended for production use to handle large updates reliably.
    |
    */
    "update" => [
        "chunk_size" => env('XG_UPDATE_CHUNK_SIZE', 10 * 1024 * 1024), // 10MB
        "download_timeout" => env('XG_UPDATE_DOWNLOAD_TIMEOUT', 300),
        "extraction_batch_size" => env('XG_UPDATE_EXTRACTION_BATCH', 100),
        "replacement_batch_size" => env('XG_UPDATE_REPLACEMENT_BATCH', 50),
        "enable_backup" => env('XG_UPDATE_ENABLE_BACKUP', true),
        "smart_vendor_replacement" => env('XG_UPDATE_SMART_VENDOR', true),
        "max_retries" => env('XG_UPDATE_MAX_RETRIES', 3),
        "status_file" => storage_path('app/xg-update/.update-status.json'),
        "temp_directory" => storage_path('app/xg-update'),
    ],
];
```


#### Environment Variables

You can customize the behavior by adding these variables to your `.env` file:

```env
# License Server Configuration
XG_LICENSE_API_URL=https://license.xgenious.com
XG_PRODUCT_TOKEN=your-unique-product-token

# V2 Update System Settings (all optional, defaults shown)
XG_UPDATE_CHUNK_SIZE=10485760        # 10MB chunk size
XG_UPDATE_DOWNLOAD_TIMEOUT=300       # 5 minutes per chunk
XG_UPDATE_EXTRACTION_BATCH=100       # Files per extraction batch
XG_UPDATE_REPLACEMENT_BATCH=50       # Files per replacement batch
XG_UPDATE_ENABLE_BACKUP=true         # Backup files before replacing (recommended)
XG_UPDATE_SMART_VENDOR=true          # Smart vendor package replacement
XG_UPDATE_MAX_RETRIES=3              # Retry attempts for failed chunks
```

## Usage

```php
$xgapiclient = new XgApiClient\XgApiClient();
echo $xgapiclient->echoPhrase('Hello, XgApiClient!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Md. Abdur Rahman](https://github.com/mar-babu)
- [Sharfiur Rahman](https://github.com/sharifur)
- [Md Zahidul Islam](https://github.com/mdzahid-pro)
- [Mazharul Islam Suzon](https://github.com/iamsuzon)
- [Rakibul Hasan](https://github.com/rakib01)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
