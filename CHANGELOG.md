# Changelog

All notable changes to `naijacloud/argus-laravel` are listed here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
package uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-26

- **Requires a patched PHP:** 8.2.35, 8.3.36, 8.4.27, 8.5.12 or newer, the
  releases that fix php2shell (CVE-2026-109618 and CVE-2026-109619).
  Composer refuses older releases. PHP 8.1 is end of life and unsupported.

First release, implementing the Argus SDK contract `argus.sdk/1` on top of
`naijacloud/argus` 0.1. Supports Laravel 10, 11, 12 and 13.

### Added

- **Zero-config setup:** package auto-discovery; `ARGUS_DSN` in `.env` is all
  it needs. Without a DSN nothing runs.
- **`config/argus.php`,** publishable with
  `php artisan vendor:publish --tag=argus-config`, and safe under
  `config:cache`.
- **Exceptions** reported through Laravel's exception handler, so
  `dontReport` and `report()` behave as configured. Each carries the route as
  its culprit, the route name, the user id, the request and breadcrumbs.
- **Request tracing** as global middleware (also aliased `argus.trace`):
  - a server span per request, named after its route;
  - the caller's `traceparent` continued;
  - the status code recorded;
  - sent after the response.
- **Child spans** for database queries (SQL without bindings) and `Http::`
  calls. Outgoing calls get a `traceparent` header.
- **The `argus` log channel driver,** defined automatically: add it with
  `LOG_STACK=single,argus`.
- **Queue integration:**
  - a consumer span per job, continuing the dispatching request's trace;
  - failed jobs reported with their class, queue and connection;
  - a flush and a scope reset after each job in a worker.
- **Scheduler check-ins** with `->argusMonitor('slug')` on any scheduled
  task.
- **`php artisan argus:test`** sends a test event and explains a missing DSN,
  a refused key, a rate limit or an unreachable Argus.
- **The `Argus` facade** over the client.
- **Octane:** a flush and a scope reset after each request.
