<p align="center">
  <a href="https://www.naijacloud.com/argus">
    <img alt="Naijacloud Argus, Laravel SDK" src="https://raw.githubusercontent.com/naijacloud/argus-laravel/main/.github/assets/banner.png" width="100%">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/naijacloud/argus-laravel"><img alt="packagist" src="https://img.shields.io/badge/packagist-naijacloud%2Fargus--laravel-008751?style=flat-square&labelColor=0A0E0C"></a>
  <img alt="laravel" src="https://img.shields.io/badge/laravel-10_%7C_11_%7C_12_%7C_13-FF2D20?style=flat-square&labelColor=0A0E0C">
  <img alt="php" src="https://img.shields.io/badge/php-%3E%3D_8.1-8892BF?style=flat-square&labelColor=0A0E0C">
  <a href="LICENSE"><img alt="license" src="https://img.shields.io/badge/license-MIT-8A988F?style=flat-square&labelColor=0A0E0C"></a>
</p>

<p align="center">
  <a href="#install">Install</a> ·
  <a href="#what-you-get">What you get</a> ·
  <a href="#logs">Logs</a> ·
  <a href="#scheduled-tasks">Scheduled tasks</a> ·
  <a href="#in-your-code">In your code</a> ·
  <a href="#configuration">Configuration</a> ·
  <a href="#octane-and-queue-workers">Octane and workers</a>
</p>

# naijacloud/argus-laravel

The official Laravel SDK for [Argus](https://www.naijacloud.com/argus),
Naijacloud's monitoring. It reports exceptions, traces requests, queries, jobs
and outgoing HTTP calls, sends your logs, and checks in your scheduled tasks,
with one key: your app's **DSN**.

It builds on [`naijacloud/argus`](https://github.com/naijacloud/argus-php),
implements the Argus SDK contract `argus.sdk/1`, and supports Laravel 10, 11,
12 and 13 on PHP 8.1 or newer.

## Install

```sh
composer require naijacloud/argus-laravel
```

Then add your DSN to `.env`. Copy it from your source in Argus:

```sh
ARGUS_DSN=https://<key>@argus-in.naijacloud.com/<id>
```

That's all. The package is discovered automatically: there is no provider to
register and no file to edit. Check it works:

```sh
php artisan argus:test
```

It sends one test event and tells you whether Argus accepted it, or why not.

Apps hosted on Naijacloud get `ARGUS_DSN`, `ARGUS_RELEASE` and
`ARGUS_ENVIRONMENT` set when the Argus tab is on. Without a DSN the package
does nothing, so local development and CI need no changes.

## What you get

With only the DSN set:

- **Exceptions** reported through Laravel's own handler. Whatever Laravel
  reports reaches Argus, and whatever it doesn't (validation errors, 404s,
  your `dontReport` list) doesn't. `report($e)` works too.
- **Each error** carries:
  - the route as its culprit (`GET /orders/{order}`) and the route name as a
    tag;
  - the signed-in user's id;
  - the request URL, without its query string;
  - breadcrumbs of the queries, log messages and HTTP calls that led to it.
- **Request tracing:** a span per request, named after its route.
  - It continues a W3C `traceparent` from the caller.
  - Each database query becomes a child span. The SQL is sent with `?`
    placeholders, never the bound values.
  - Each `Http::` call becomes a child span, and carries a `traceparent` so
    the service you call joins the trace.
- **Queued jobs:**
  - each job runs in a span that continues the trace of the request that
    dispatched it;
  - a failed job is reported with its class, queue and connection as tags;
  - workers send after every job, and start each job with a clean scope.
- **Sent after the response:** under PHP-FPM, everything goes out after the
  response has reached the visitor, within a 2-second budget.

## Logs

The `argus` log channel is defined for you. Add it to your stack in `.env`:

```sh
LOG_STACK=single,argus
```

Every `Log::info()`, `Log::warning()` and so on now also reaches Argus Logs,
with its context as attributes and the request's trace id. On Laravel 10, or
with a custom `config/logging.php`, add `'argus'` to your stack channel's
`channels` instead.

```php
Log::channel('argus')->info('order {id} placed', ['id' => $order->id]);
```

Set the lowest level sent with `ARGUS_LOG_LEVEL` (default `debug`). An
exception logged at `error` or above is also sent as an Argus error, once,
even when Laravel reports it too.

## Scheduled tasks

Create a cron monitor in Argus, then name it on the task:

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
Schedule::command('invoices:send')->daily()->argusMonitor('nightly-invoices');
```

Argus hears when the task starts, and whether it succeeded or failed (with
its exit code). If the monitor doesn't exist yet, the check-ins are ignored
and the task runs as usual.

## In your code

The `Argus` facade is registered for you:

```php
use NaijaCloud\Argus\Laravel\Facades\Argus;

Argus::captureException($e, ['tags' => ['provider' => 'paystack']]);
Argus::captureMessage('Webhook signature mismatch', 'warning');
Argus::setTag('tenant', $tenant->slug);

Argus::count('orders.placed', 1, ['method' => 'card']);
Argus::distribution('checkout.ms', $elapsedMs, [], 'ms');

Argus::trace('charge card', fn () => $paystack->charge($order));

if (Argus::isOn('new-checkout', (string) $user->id)) {
    // …
}

Argus::withMonitor('import-rates', fn () => $this->importRates());
```

Everything in [`naijacloud/argus`](https://github.com/naijacloud/argus-php)
works too. That includes the static `NaijaCloud\Argus\Argus` class, with
`Argus::logs()`, `Argus::metrics()`, `Argus::cron()` and `Argus::flags()`, and
it uses the same client.

## Configuration

Everything has a default. To change one, publish the config file:

```sh
php artisan vendor:publish --tag=argus-config
```

| `.env` | Default | |
| -- | -- | -- |
| `ARGUS_DSN` | `SENTRY_DSN` | Without one, nothing is sent |
| `ARGUS_RELEASE` | `SENTRY_RELEASE` | Your app's version or git commit |
| `ARGUS_ENVIRONMENT` | `SENTRY_ENVIRONMENT`, else `APP_ENV` | |
| `ARGUS_SERVICE_NAME` | `APP_NAME` | Shown on logs, metrics and traces |
| `ARGUS_SAMPLE_RATE` | `1` | Share of errors sent |
| `ARGUS_TRACES_SAMPLE_RATE` | `1` | Share of traces sent |
| `ARGUS_SEND_DEFAULT_PII` | `false` | Also send the user's IP, email and name |
| `ARGUS_LOG_LEVEL` | `debug` | Lowest level the `argus` log channel sends |
| `ARGUS_FLUSH_TIMEOUT_MS` | `2000` | Most time spent sending after a response |
| `ARGUS_FLAGS_TIMEOUT_MS` | `2000` | Most time a flag check waits |
| `ARGUS_DEBUG` | `false` | Write what the SDK does to the PHP error log |

The config file also has these settings:

- **`tracing.*`:** turn request, query, job and HTTP-client spans on or off,
  and set `ignore_paths` (`up` by default).
- **`breadcrumbs.*`:** turn each kind of breadcrumb on or off.
- **`ignore_exceptions`:** exception classes never sent.
- **`before_send`:** a callable that changes or drops events. Set it in the
  published file, since `.env` cannot hold one.

Works with `php artisan config:cache`: the DSN is read in the config file, as
Laravel requires.

## Octane and queue workers

- **Octane:** the request middleware sends after each request, and clears the
  user and tags between requests.
- **Queue workers** (`queue:work`, Horizon): Argus sends after each job, and
  each job starts with a clean scope.
- **`schedule:work`:** sends each check-in as it happens.

## Privacy

On by default:

- **Sensitive values:** passwords, tokens, secrets, card numbers, BVNs,
  `Authorization` headers and JWTs are replaced with `[Filtered]`.
- **Not sent:** cookies, request bodies and query strings.
- **SQL:** sent with `?` placeholders, never the bound values.
- **The user:** only the id is sent, unless you set
  `ARGUS_SEND_DEFAULT_PII=true`.

Argus filters again on arrival.

## License

MIT
