# Contributing

## Running the checks

The host needs Docker and nothing else. `bin/composer` runs Composer, and
`bin/php` runs PHP, in a container built from `docker/php/Dockerfile` with
the checkout mounted at `/app`.

```sh
bin/composer install
bin/composer check       # validate, lint, analyse, test: what a pull request runs
bin/composer test:unit   # the unit tests
bin/composer analyse     # PHPStan, level max
bin/composer lint        # PHP-CS-Fixer (PER-CS 2.0), without changing files
bin/composer format      # PHP-CS-Fixer, fixing files
```

The container runs PHP 8.1, the minimum the package supports, and the lock
file is resolved for it. `PHP_VERSION=<version>` selects another
`php:<version>-cli` image; pull requests run the checks on 8.1 and on the
newest stable PHP.

## The API spec

The SDK is built from one published release of the Pixonaut API. `spec/`
holds a copy of that release's `openapi.json` and
`webhook-signature-vectors.json`, and `spec/spec.lock.json` names the
version and the sha256 of both files.

```sh
bin/spec pin <version>   # pin another release from https://cdn.pixonaut.app/api/<version>/
bin/spec pin             # pin the pinned release again: changes nothing
bin/spec check           # the files hash to the lock (offline; part of `composer check`)
bin/spec check --online  # and the CDN still serves the same release
```

Change the files in `spec/` only with `bin/spec pin`: a pull request runs
`bin/spec check`, which fails on a hand edit. A pin downloads both files and
holds them to the release's `integrity.json` before writing anything, so a
failed pin leaves the previous one in place. After a pin to another version
it prints oasdiff's changelog between the two specs, breaking changes first,
and says whether the next SDK release must be a major. Commit the new pin
together with the regenerated SDK.

A scheduled workflow runs `bin/spec check --online` weekly.

## Commits

Commits follow [Conventional Commits](https://www.conventionalcommits.org/):
`type(scope): subject`, with `feat`, `fix`, `perf`, `refactor`, `docs`,
`test`, `build`, `ci`, `chore`, `style` or `revert` as the type. Pull requests
are squash-merged, so the pull request title becomes the commit on `main`;
a check refuses a title that is not a Conventional Commits subject.

Mark a breaking change with `!` after the type (`feat!: drop PHP 8.1`) or a
`BREAKING CHANGE:` footer. Release-please reads these commits to choose the
next version and write `CHANGELOG.md`.

## Prefixing

Integrations prefix the package with Strauss or PHP-Scoper, which rewrite
class names they can see in the source. Code in `src/` names every class
statically: no `new $class`, no `$class::method()`, no `instanceof $class`,
no callable built from a string, no class name in a string.
`tests/Guard/NoDynamicClassNamesTest.php` enforces it.

## Releases

Release-please keeps a release pull request open from the commits on `main`;
merging it tags the version and creates the GitHub Release. For the pull
request checks to run on that release pull request, the repository needs a
`RELEASE_PLEASE_TOKEN` secret (a GitHub App token or fine-grained token with
contents and pull requests write access): a pull request opened with the
default `GITHUB_TOKEN` starts no workflows.
