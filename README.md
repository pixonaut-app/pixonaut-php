# pixonaut-php

PHP SDK for the [Pixonaut](https://pixonaut.app) public API, for PHP
integrations such as WooCommerce plugins. It depends only on the PSR HTTP
and logging interfaces, so it works with any PSR-18 client, and it can be
prefixed with Strauss or PHP-Scoper.

Each release targets one release of the Pixonaut API, named in its release
notes and in `Pixonaut\Sdk\Generated\ApiVersion::TARGET`.

## Install

PHP 8.1 or newer, and any PSR-18 client with PSR-17 factories:

```sh
composer require pixonaut/pixonaut-php symfony/http-client nyholm/psr7
```

## Use

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Pixonaut\Sdk\Config;
use Pixonaut\Sdk\Credentials\StaticCredentials;
use Pixonaut\Sdk\Generated\Operations;
use Pixonaut\Sdk\Pixonaut;
use Symfony\Component\HttpClient\Psr18Client;

$factory = new Psr17Factory();
$pixonaut = new Pixonaut(
    new Config(product: 'my-shop/1.0'),
    new Psr18Client(),
    $factory,
    $factory,
    new StaticCredentials(integrationKey: $integrationKey),
    Operations::catalogue(),
);

$template = $pixonaut->templates()->get('tshirt-basic@2');
```

Every operation is a method on its resource (`templates()`, `designs()`,
`orders()`, …), taking typed arguments and returning a typed model. A call
that does not succeed throws `Pixonaut\Sdk\Exception\ApiException`.

## Documentation

- [Quick start](docs/quick-start.md): connect a store, read templates,
  commit a design, handle errors and take a webhook delivery. Every snippet
  runs in the package's tests.
- [Errors](docs/errors.md): the codes, what is retryable, and what to retry.
- [Idempotency](docs/idempotency.md): snapshots, resends, what to store.
- [Webhooks](docs/webhooks.md): the signature, its window, rotation, unknown
  events.
- [Versioning](docs/versioning.md): how releases are numbered and which API
  each targets.

## Prefixing

Plugins that ship their dependencies prefix them, so two plugins with
different versions do not clash. The SDK names every class statically, and
each pull request prefixes the package and its dependencies with
[Strauss](https://github.com/BrianHenryIE/strauss) and with
[PHP-Scoper](https://github.com/humbug/php-scoper), then runs the quick-start
and the webhook signature vectors on the prefixed copy.

## Working on the package

Everything runs in Docker; the host needs no PHP or Composer.

```sh
bin/composer install        # install the dependencies
bin/composer check          # everything a pull request runs
bin/composer test           # the tests only
bin/composer format         # fix the code style
PHP_VERSION=8.5 bin/composer check   # on another PHP version
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the tests, the generated code
and the commit convention.

## License

[MIT](LICENSE)
