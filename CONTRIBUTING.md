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
bin/spec diff [<old>]    # oasdiff from <old> (default: the spec a pin replaced) to the pinned spec
```

Change the files in `spec/` only with `bin/spec pin`: a pull request runs
`bin/spec check`, which fails on a hand edit. A pin downloads both files and
holds them to the release's `integrity.json` before writing anything, so a
failed pin leaves the previous one in place. After a pin to another version
it prints oasdiff's changelog between the two specs, breaking changes first,
and says whether the next SDK release must be a major.
If that comparison fails, the replaced spec is kept and `bin/spec diff`
runs it again. Commit the new pin
together with the regenerated SDK.

A scheduled workflow runs `bin/spec check --online` weekly.

## The generated code

`src/Generated/` is printed from the pinned spec by the generator in
`generator/Codegen/` and is committed. Never edit it by hand: change the
generator or pin another spec, then regenerate.

```sh
bin/generate           # regenerate src/Generated/, removing files the spec no longer produces
bin/generate --check   # fail, listing each file, when src/Generated/ is stale (part of `composer check`)
```

It holds:

- `Model/`: one `final class` per component schema and per object schema of
  a request or response body, named after the operation and its place
  (`OrdersCreateRequestPrint`). `fromArray()` checks the fields this release
  knows and throws `UnexpectedResponse` naming the JSON path; unknown fields
  stay in `raw()`. `toArray()` gives what a request sends: an optional field
  that may be `null` defaults to `Omitted::Field` and is left out, so `null`
  is sent only when set. A `oneOf`/`anyOf` gets a decoder class with a
  `decode()` method and a class per variant; a variant whose fields allow
  one value only is tried before one that would also accept the value.
- `Enum/`: the known values of each enum as constants. The fields stay
  strings, since a later API release may answer a value this one does not
  know.
- `ErrorCode`, `Operations` (with `Operations::catalogue()` for the client)
  and `ApiVersion`.

`generator/config.php` holds what the spec does not say, each entry with its
reason: which operations answer secrets (so an error keeps none of their
body), and which `x-sdk-skip` operations the SDK still calls. A test fails on
an answer field named like a secret (`key`, `secret`, `token`) on an
operation that is not listed, unless the config says why it is not one.

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
