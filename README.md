# this package is for manage license management system for xgenious interls

[![Latest Version on Packagist](https://img.shields.io/packagist/v/xgenious/xgapiclient.svg?style=flat-square)](https://packagist.org/packages/xgenious/xgapiclient)
[![GitHub Tests Action Status](https://img.shields.io/github/workflow/status/xgenious/xgapiclient/run-tests?label=tests)](https://github.com/xgenious/xgapiclient/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/workflow/status/xgenious/xgapiclient/Check%20&%20fix%20styling?label=code%20style)](https://github.com/xgenious/xgapiclient/actions?query=workflow%3A"Check+%26+fix+styling"+branch%3Amain)
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
