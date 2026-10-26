# Contributing

## What this package is

The Laravel integration for Argus. Everything that is not Laravel-specific
(the wire format, transport, scrubbing and the client API) lives in
[`naijacloud/argus`](https://github.com/naijacloud/argus-php) and is fixed
there. This package wires that client into Laravel's container, exception
handler, middleware, log manager, queue, scheduler and events.

## Getting set up

Clone `argus-php` next to this repository, then install against it with the
local Composer file, which is gitignored:

```bash
git clone https://github.com/naijacloud/argus-php ../argus-php
php -r '$c = json_decode(file_get_contents("composer.json"), true); $c["repositories"] = [["type" => "path", "url" => "../argus-php"]]; file_put_contents("composer.local.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
COMPOSER=composer.local.json composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

Never add a `repositories` entry to `composer.json`. The published package
requires `naijacloud/argus` from Packagist, and the release workflow refuses
a tag that doesn't.

Once `naijacloud/argus` is on Packagist, a plain `composer install` works too.

## Tests

**The suite runs offline.**

- **The app:** the tests boot a Laravel app with Orchestra Testbench, the
  package auto-registered, and `argus.dsn` pointing at `tests/Support/router.php`.
  That is a `php -S` stand-in for Argus ingest that records every request it
  gets.
- **What they assert:** the payloads Argus would receive, not the calls
  inside the SDK.
- **Scenarios:** the scenario (ok, 401, 429, 500 ...) rides in the DSN's path
  prefix, the same as in `argus-php`.

CI runs Laravel 10 and 11 on PHP 8.2, 12 on 8.3, and 13 on 8.3, 8.4 and 8.5, each
the minor's latest patched release.
Before a pull request, run:

```bash
composer validate
find src tests config -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit
```

## House style

The same as `argus-php`:

- `declare(strict_types=1)`, PSR-12.
- Comments that explain why.
- No emoji.
- **Listeners never break the app.** Every listener and the middleware catch
  their own failures, so a broken Argus can never break the event or request
  it observes.

## Releasing

1. Release `naijacloud/argus` first if this version needs a newer core, and
   raise the constraint in `composer.json`.
2. Change the `version` field in `composer.json`, and move the `Unreleased`
   changelog entries under it with a date.
3. Tag and push `vX.Y.Z`. Packagist picks it up from the tag.

`.github/workflows/release.yml` runs the CI matrix, then checks the release
before creating it:

- the tag matches `composer.json`;
- the changelog has a section for the version;
- `composer.json` has no `repositories` entry;
- `naijacloud/argus` resolves from Packagist.

## Security

See [SECURITY.md](SECURITY.md). Never report a vulnerability in an issue.
