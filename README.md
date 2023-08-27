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

This is the contents of the published config file:

```php
return [
];
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

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
