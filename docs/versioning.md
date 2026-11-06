# Versioning

The SDK has its own [semantic version](https://semver.org/), and each release
targets one release of the Pixonaut API.

## Which API a release targets

Three places name it:

- `Pixonaut\Sdk\Generated\ApiVersion::TARGET`, at run time;
- `extra.pixonaut.api-version` in `composer.json`, for tools that read the
  package without loading it;
- the first line of the release's notes in `CHANGELOG.md` and on the GitHub
  Release, e.g. *Targets the Pixonaut API 1.0.0.*

A test holds `ApiVersion::TARGET` and `composer.json` equal, and the release
workflow writes the notes' first line from `composer.json`.

The SDK is generated from that API release's published spec, pinned by
version and sha256 in `spec/spec.lock.json`.

## What makes a major, a minor or a patch

| Change | Release |
| --- | --- |
| A fix that changes no public signature | patch (`fix:`) |
| A new API minor that only adds: new operations, fields, event types | minor (`feat:`) |
| Anything else that only adds to the SDK's public surface | minor (`feat:`) |
| A pinned API release whose comparison lists a breaking change | major |
| A public class, method, signature or constant removed or changed | major |

A major is marked in the pull request title with `!` (`feat!: …`), or by a
`BREAKING CHANGE:` footer on the commit; release-please reads it and
releases the next major.

Within a major, code written against the SDK keeps compiling and running.
Answers may grow: a field or an enum value the API adds later is kept in a
model's `raw()`, an unknown event type reads as an `UnknownEvent`, and an
unknown error code arrives as it was sent. So a store does not break when
Pixonaut adds to the API before the SDK is updated.

## The public surface

`api-surface.txt` lists every public class, constant, property and method
signature in `src/`, except classes marked `@internal`. `bin/surface`
writes it and `bin/surface --check` fails when it is stale; a pull request
runs both checks:

- the surface must be current, so every change to it is in the diff;
- a pull request that removes or changes a line of it fails unless its
  title is marked breaking.

Adding a line is never breaking.

## Pinning a new API release

`bin/spec pin <version>` downloads the release and prints the
[oasdiff](https://github.com/oasdiff/oasdiff) changelog from the spec it
replaces, breaking changes first, and says whether the next SDK release must
be a major. Regenerate the SDK (`bin/generate`), the surface
(`bin/surface`), set `extra.pixonaut.api-version` in `composer.json`, and
commit them together, with a title marked breaking when oasdiff or the
surface says so.

## PHP versions

The SDK supports PHP 8.1 and newer. Dropping a PHP version is a major
(`feat!: drop PHP 8.1`).
