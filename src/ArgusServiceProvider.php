<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel;

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Log\LogManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;
use NaijaCloud\Argus\Argus;
use NaijaCloud\Argus\Client;
use NaijaCloud\Argus\Laravel\Console\TestCommand;
use NaijaCloud\Argus\Laravel\Http\TraceRequests;
use NaijaCloud\Argus\Laravel\Integrations\EventIntegration;
use NaijaCloud\Argus\Laravel\Integrations\ExceptionReporter;
use NaijaCloud\Argus\Log\MonologHandler;

/**
 * Auto-discovered. With ARGUS_DSN in .env this reports exceptions through
 * Laravel's handler, traces requests, queries, jobs and Http:: calls, and
 * offers the `argus` log channel, `argus:test` and `->argusMonitor()` on
 * scheduled tasks. Without a DSN it registers nothing that runs.
 */
final class ArgusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/argus.php', 'argus');

        $this->app->singleton(Client::class, static function (Application $app): Client {
            return Argus::init(self::options($app));
        });
        $this->app->alias(Client::class, 'argus');

        // The channel exists without editing config/logging.php, so
        // LOG_STACK=single,argus in .env is all it takes to send logs.
        $config = $this->app->make('config');
        if ($config->get('logging.channels.argus') === null) {
            $config->set('logging.channels.argus', [
                'driver' => 'argus',
                'level' => $config->get('argus.logs.level', 'debug'),
            ]);
        }
        $this->callAfterResolving('log', static function (LogManager $log, Application $app): void {
            $log->extend('argus', function ($_app, array $channel) use ($app): Logger {
                return new Logger(
                    is_string($channel['name'] ?? null) ? $channel['name'] : 'argus',
                    [new MonologHandler($app->make(Client::class), $channel['level'] ?? 'debug', (bool) ($channel['bubble'] ?? true))],
                );
            });
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/argus.php' => $this->app->configPath('argus.php')], 'argus-config');
        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class]);
        }

        // Defined whether or not a DSN is set, so a schedule that uses it
        // still loads in an environment without one.
        if (!ScheduledEvent::hasMacro('argusMonitor')) {
            ScheduledEvent::macro('argusMonitor', function (string $slug): ScheduledEvent {
                /** @var ScheduledEvent $this */
                return Scheduling::monitor($this, $slug);
            });
        }

        if ($this->app->bound('router')) {
            $this->app->make(Router::class)->aliasMiddleware('argus.trace', TraceRequests::class);
        }

        $client = $this->app->make(Client::class);
        if (!$client->isEnabled()) {
            return;
        }

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            ExceptionReporter::register($handler, $this->app);
        });

        if ($this->app->make(Config::class)->get('argus.tracing.requests', true) && $this->app->bound(HttpKernel::class)) {
            $kernel = $this->app->make(HttpKernel::class);
            if ($kernel instanceof \Illuminate\Foundation\Http\Kernel) {
                $kernel->prependMiddleware(TraceRequests::class);
            }
        }

        // Monitoring must never be the reason an app fails to boot.
        try {
            (new EventIntegration($this->app, $client))->register();
        } catch (\Throwable $e) {
            $client->debug('Laravel integration failed to register: ' . $e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private static function options(Application $app): array
    {
        $c = $app->make('config');
        $options = [
            'dsn' => $c->get('argus.dsn'),
            'release' => $c->get('argus.release'),
            'environment' => $c->get('argus.environment') ?: $app->environment(),
            'service_name' => $c->get('argus.service_name') ?: $c->get('app.name'),
            'sample_rate' => (float) $c->get('argus.sample_rate', 1.0),
            'traces_sample_rate' => (float) $c->get('argus.traces_sample_rate', 1.0),
            'send_default_pii' => (bool) $c->get('argus.send_default_pii', false),
            'debug' => (bool) $c->get('argus.debug', false),
            'flush_timeout_ms' => (int) $c->get('argus.flush_timeout_ms', 2000),
            'flags_timeout_ms' => (int) $c->get('argus.flags_timeout_ms', 1000),
            'ignore_exceptions' => (array) $c->get('argus.ignore_exceptions', []),
            'project_root' => $app->basePath(),
            // Laravel installs its own error and exception handlers and turns
            // PHP errors into exceptions that reach its handler; reporting
            // goes through that, so Argus's global ones would only double up.
            'register_handlers' => false,
        ];
        foreach (['before_send', 'before_breadcrumb', 'http_client', 'tags', 'in_app_exclude', 'sleeper', 'logger'] as $key) {
            if ($c->get('argus.' . $key) !== null) {
                $options[$key] = $c->get('argus.' . $key);
            }
        }

        return $options;
    }
}
