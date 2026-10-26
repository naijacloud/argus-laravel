<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Integrations;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use NaijaCloud\Argus\Client;

/**
 * Reports through Laravel's exception handler, so everything Laravel reports
 * reaches Argus and nothing it doesn't: $dontReport, `->dontReport()` in
 * bootstrap/app.php, `report()` and exceptions from jobs and commands all
 * behave as configured.
 *
 * @internal
 */
final class ExceptionReporter
{
    public static function register(ExceptionHandler $handler, Application $app): void
    {
        if (!method_exists($handler, 'reportable')) {
            return;
        }
        // Returning nothing lets Laravel go on to log the exception as usual.
        $handler->reportable(static function (\Throwable $e) use ($app): void {
            self::capture($app, $e);
        });
    }

    public static function capture(Application $app, \Throwable $e, string $level = 'error'): void
    {
        try {
            $client = $app->make(Client::class);
            if (!$client->isEnabled() || $client->wasCaptured($e)) {
                return;
            }
            Context::apply($app, $client);
            $client->captureException($e, ['level' => $level]);
        } catch (\Throwable) {
            // reporting must never be why a request fails
        }
    }
}
