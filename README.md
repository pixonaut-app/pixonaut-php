# pixonaut-php

PHP SDK for the [Pixonaut](https://pixonaut.app) public API, for PHP
integrations such as WooCommerce plugins. It depends only on the PSR HTTP
and logging interfaces, so it works with any PSR-18 client, and it can be
prefixed with Strauss or PHP-Scoper.

**Pre-release.** Nothing is published yet and the API may change until 1.0.0.

## Requirements

PHP 8.1 or newer.

```sh
composer require pixonaut/pixonaut-php
```

## Working on the package

Everything runs in Docker; the host needs no PHP or Composer.

```sh
bin/composer install        # install the dependencies
bin/composer check          # everything a pull request runs
bin/composer test           # the tests only
bin/composer format         # fix the code style
PHP_VERSION=8.5 bin/composer check   # on another PHP version
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the commit convention.

## License

[MIT](LICENSE)
