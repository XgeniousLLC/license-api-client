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
    | The license server every product talks to. Fixed - not .env-driven.
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
    | Internal tuning for the chunked update system (V2) - fixed defaults,
    | not .env-driven.
    |
    */
    "update" => [
        "chunk_size" => 10 * 1024 * 1024, // 10MB
        "download_timeout" => 300,
        "extraction_batch_size" => 100,
        "replacement_batch_size" => 50,
        "enable_backup" => true,
        "smart_vendor_replacement" => true,
        "max_retries" => 3,
        "status_file" => storage_path('app/xg-update/.update-status.json'),
        "temp_directory" => storage_path('app/xg-update'),
    ],
];
```


#### Environment Variables

Only one variable is needed in your `.env` file:

```env
XG_PRODUCT_TOKEN=your-unique-product-token
```

Everything else (which license server to talk to, chunk size, batch sizes,
retry count, backup, smart-vendor-replacement) is a fixed internal default as
of 6.5.3 - not `.env`-configurable, so a mistyped or malformed override on the
hosting side can no longer break the update pipeline or misdirect licensing.

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
