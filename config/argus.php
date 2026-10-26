<?php

/*
 * Argus for Laravel. Only ARGUS_DSN is needed; everything else has a default.
 *
 * Without a DSN, Argus does nothing and your app runs exactly as before.
 * Publish this file with: php artisan vendor:publish --tag=argus-config
 */

return [

    // https://<key>@argus-in.naijacloud.com/<id>, from your source in Argus.
    'dsn' => env('ARGUS_DSN', env('SENTRY_DSN')),

    // Your app's version, like 1.4.2 or a git commit.
    'release' => env('ARGUS_RELEASE', env('SENTRY_RELEASE')),

    // Defaults to APP_ENV when neither variable is set.
    'environment' => env('ARGUS_ENVIRONMENT', env('SENTRY_ENVIRONMENT')),

    // service.name on logs, metrics and traces. Defaults to APP_NAME.
    'service_name' => env('ARGUS_SERVICE_NAME'),

    // Share of errors sent, and of traces, from 0 to 1.
    'sample_rate' => (float) env('ARGUS_SAMPLE_RATE', 1.0),
    'traces_sample_rate' => (float) env('ARGUS_TRACES_SAMPLE_RATE', 1.0),

    // Send the user's IP address, email and username. Off by default; the
    // signed-in user's id is always sent.
    'send_default_pii' => (bool) env('ARGUS_SEND_DEFAULT_PII', false),

    // Write what the SDK does to the PHP error log.
    'debug' => (bool) env('ARGUS_DEBUG', false),

    // The most time a request spends sending to Argus after the response, and
    // the most a feature-flag check waits, in milliseconds.
    'flush_timeout_ms' => (int) env('ARGUS_FLUSH_TIMEOUT_MS', 2000),
    'flags_timeout_ms' => (int) env('ARGUS_FLAGS_TIMEOUT_MS', 2000),

    // A callable that may change an event, or return null to drop it. Use a
    // [class, method] pair rather than a closure so config:cache still works:
    // 'before_send' => [App\Support\ArgusFilters::class, 'beforeSend'],
    'before_send' => null,

    // Exception classes never sent. Laravel's own $dontReport list is
    // respected too, so validation errors, 404s and the like are not sent.
    'ignore_exceptions' => [],

    'tracing' => [
        // A span per HTTP request, named after its route.
        'requests' => true,
        // Paths not traced (Laravel's health check by default).
        'ignore_paths' => ['up'],
        // A child span per database query.
        'queries' => true,
        // A span per queued job, continuing the trace that dispatched it.
        'queue_jobs' => true,
        // A child span per Http:: call, with a traceparent header added.
        'http_client' => true,
    ],

    'breadcrumbs' => [
        'logs' => true,
        'queries' => true,
        'queue' => true,
        'http_client' => true,
        'commands' => true,
    ],

    // The lowest level the `argus` log channel sends to Argus Logs. Add the
    // channel with LOG_STACK=single,argus (Laravel 11+), or list `argus` in
    // your stack channel's `channels`.
    'logs' => [
        'level' => env('ARGUS_LOG_LEVEL', 'debug'),
    ],

];
