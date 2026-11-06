# Contributing

## Running the checks

The host needs Docker and nothing else. `bin/composer` runs Composer, and
`bin/php` runs PHP, in a container built from `docker/php/Dockerfile` with
the checkout mounted at `/app`.

```sh
bin/composer install
bin/composer check       # validate, lint, analyse, test: what a pull request runs
bin/composer test        # the unit and contract tests
bin/composer test:unit   # the unit tests
bin/composer test:contract  # the SDK against the fake Pixonaut API
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

## Tests

No test reaches a real Pixonaut. The test bootstrap points every HTTP
client and PHP's stream wrapper at a proxy that refuses all connections, and
lets only loopback through; pull requests also run the suites in a container
with no network but loopback (`DOCKER_NETWORK=none bin/composer test` does
the same locally).

- `tests/Unit/` proves each piece against scripted answers.
- `tests/Contract/` runs the SDK end to end against `tests/Fake/`, a fake of
  the API driven by the pinned spec. `FakePixonaut` is a PSR-18 client in
  memory: it routes each request by method and path template, checks its
  path values, query, headers, body and credential against the operation's
  schema with `opis/json-schema`, and answers with the spec's example, or
  with what a test set (`answer()`, `answerExample()`). It answers a
  repeated `Idempotency-Key` as Pixonaut does, and a test can script faults
  (`Fault::serverError()`, `rateLimited()`, `deprecated()`,
  `transportFailure()`, `lostAnswer()`, `notJson()`) and a key still in
  flight (`keyInFlight()`). Every answer it gives is checked against the
  spec's response schema too. What a request breaks is a violation, and a
  contract test fails with it. `OperationsTest` calls every operation the
  SDK sends through its resource method, with arguments built from the
  spec's examples; `WireTest` runs the same fake behind `php -S`
  (`tests/Fake/router.php`) and Guzzle, on `FAKE_PIXONAUT_PORT` when it is
  set and on a free loopback port otherwise.
- `tests/Live/` is a read-only smoke against a Pixonaut on this machine, not
  part of `composer test` or CI. It skips unless `PIXONAUT_LIVE_API_URL` and
  `PIXONAUT_LIVE_SECRET_KEY` (and optionally `PIXONAUT_LIVE_INTEGRATION_KEY`)
  are set, and refuses any host that is not loopback. The container reaches
  the host's loopback only on the host network:

  ```sh
  DOCKER_NETWORK=host PIXONAUT_LIVE_API_URL=http://127.0.0.1:<port> \
      PIXONAUT_LIVE_SECRET_KEY=sk_... bin/composer test:live
  ```

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
  `decode()` method and a class per variant. A value whose tags (required
  fields a variant allows one value for) name one variant is that variant;
  otherwise the variants are tried most specific first.
- `Enum/`: the known values of each enum as constants. The fields stay
  strings, since a later API release may answer a value this one does not
  know.
- `Model/WebhookEvent`: decodes a webhook delivery's body into the event
  class its `type` names, one per entry of the spec's `webhooks` section
  (`OrderStatusChangedEvent`). A list of events, such as `GET /v1/events`
  answers, decodes the same way. A type this release does not know becomes
  `Webhooks\UnknownEvent`, never an error, so an API that adds an event type
  does not break an older SDK.
- `EventType`: each webhook's event type as a constant.
- `Resource/`: one class per resource, reached from the client
  (`$pixonaut->designs()`, through the `Resources` trait), with one method per
  operation named after its `operationId` (`designs.revisions.get` →
  `getRevision()`). Path parameters come first and are required; query
  parameters, the body and declared headers (`Prefer`,
  `x-pixonaut-session`) are typed parameters. A method returns the
  operation's response model, `Binary` for a file, an `EventStream` for
  server-sent events (read as the caller iterates, the body left unread
  until then), `void` for an empty answer, or a union when statuses answer
  differently. An idempotent
  operation also has `prepare…()`, returning the `PreparedRequest` to store,
  and `send…()`, sending a stored one.
- `ErrorCode`, `Operations` (with `Operations::catalogue()` for the client)
  and `ApiVersion`.

`generator/config.php` holds what the spec does not say, each entry with its
reason: which operations answer secrets (so an error keeps none of their
body), which `x-sdk-skip` operations the SDK still calls, which resource each
tag's operations are on, and the method names where the derived one reads
badly. An operation in a tag the config does not map stops the generator,
and a test fails when an operation the SDK generates has no method. A test fails on
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
