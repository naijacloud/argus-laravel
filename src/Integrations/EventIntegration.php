<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Integrations;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Queue;
use NaijaCloud\Argus\Client;
use NaijaCloud\Argus\Internal\Scrubber;
use NaijaCloud\Argus\Span;
use Psr\Http\Message\RequestInterface;

/**
 * Laravel events turned into breadcrumbs, spans and check-ins: database
 * queries, log messages, queued jobs, Http:: calls and artisan commands.
 * Each listener is guarded; none of them can break the event it observes.
 *
 * @internal
 */
final class EventIntegration
{
    private const PAYLOAD_KEY = 'argus_traceparent';

    private ?Span $jobSpan = null;

    /** @var \WeakMap<object,Span> */
    private \WeakMap $httpSpans;

    public function __construct(private readonly Application $app, private readonly Client $client)
    {
        $this->httpSpans = new \WeakMap();
    }

    public function register(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $config = $this->app->make(Config::class);
        $crumbs = static fn (string $k): bool => (bool) $config->get('argus.breadcrumbs.' . $k, true);
        $tracing = static fn (string $k): bool => (bool) $config->get('argus.tracing.' . $k, true);

        if ($crumbs('queries') || $tracing('queries')) {
            $events->listen(QueryExecuted::class, fn (QueryExecuted $e) => $this->guard(fn () => $this->query($e, $crumbs('queries'), $tracing('queries'))));
        }
        if ($crumbs('logs')) {
            $events->listen(MessageLogged::class, fn (MessageLogged $e) => $this->guard(fn () => $this->logged($e)));
        }
        if ($crumbs('commands')) {
            $events->listen(CommandStarting::class, fn (CommandStarting $e) => $this->guard(fn () => $this->client->addBreadcrumb([
                'category' => 'artisan', 'message' => 'Started ' . (string) $e->command,
            ])));
            $events->listen(CommandFinished::class, fn (CommandFinished $e) => $this->guard(fn () => $this->client->addBreadcrumb([
                'category' => 'artisan', 'message' => 'Finished ' . (string) $e->command, 'data' => ['exit_code' => $e->exitCode],
            ])));
        }

        $this->queue($events, $crumbs('queue'), $tracing('queue_jobs'));
        $this->httpClient($events, $crumbs('http_client'), $tracing('http_client'));
    }

    private function query(QueryExecuted $e, bool $crumb, bool $span): void
    {
        $sql = $e->sql;
        $ms = (float) $e->time;
        if ($crumb) {
            $this->client->addBreadcrumb([
                'type' => 'query',
                'category' => 'db.query',
                'message' => strlen($sql) > 1000 ? substr($sql, 0, 1000) . '...' : $sql,
                'data' => ['connection' => $e->connectionName, 'duration_ms' => $ms],
            ]);
        }
        // Only inside a request or job: a span per query in a migration or a
        // tinker session would be a trace of nothing.
        if ($span && $this->client->activeSpan() !== null) {
            $end = microtime(true);
            $driver = method_exists($e->connection, 'getDriverName') ? (string) $e->connection->getDriverName() : 'sql';
            $this->client->startSpan(self::queryName($sql), [
                'db.system' => $driver,
                'db.namespace' => (string) $e->connection->getDatabaseName(),
                'db.query.text' => strlen($sql) > 2000 ? substr($sql, 0, 2000) : $sql,
                'laravel.db.connection' => $e->connectionName,
            ], Span::KIND_CLIENT, false, null, $end - $ms / 1000)->end(null, $end);
        }
    }

    /** "SELECT users", the OpenTelemetry convention for a query span name. */
    private static function queryName(string $sql): string
    {
        if (preg_match('/^\s*(select|insert|update|delete|replace|upsert|with|create|alter|drop|truncate)\b/i', $sql, $m) !== 1) {
            return 'db.query';
        }
        $verb = strtoupper($m[1]);
        if (preg_match('/\b(?:from|into|update|table)\s+[`"\[]?([A-Za-z0-9_.]+)/i', $sql, $t) === 1) {
            return $verb . ' ' . $t[1];
        }

        return $verb;
    }

    private function logged(MessageLogged $e): void
    {
        $level = match ($e->level) {
            'emergency', 'alert', 'critical' => 'fatal',
            'error' => 'error',
            'warning' => 'warning',
            'debug' => 'debug',
            default => 'info',
        };
        $this->client->addBreadcrumb([
            'type' => 'default',
            'category' => 'log',
            'level' => $level,
            'message' => (string) $e->message,
        ]);
    }

    private function queue(Dispatcher $events, bool $crumbs, bool $spans): void
    {
        // The trace that dispatched a job continues in the worker.
        if ($spans) {
            Queue::createPayloadUsing(function (): array {
                $tp = $this->client->traceparent();

                return $tp === null ? [] : [self::PAYLOAD_KEY => $tp];
            });
        }

        $events->listen(JobProcessing::class, fn (JobProcessing $e) => $this->guard(function () use ($e, $crumbs, $spans): void {
            $sync = $e->connectionName === 'sync';
            if (!$sync) {
                // A worker runs many jobs in one process: start each clean.
                $this->client->clearScope();
            }
            $name = $e->job->resolveName();
            $this->client->setTags(['queue.job' => $name, 'queue.connection' => $e->connectionName, 'queue.name' => (string) $e->job->getQueue()]);
            if (!$sync) {
                $this->client->setTransaction($name);
            }
            if ($crumbs) {
                $this->client->addBreadcrumb(['category' => 'queue.job', 'message' => 'Processing ' . $name, 'data' => ['attempts' => $e->job->attempts()]]);
            }
            if ($spans) {
                $payload = $e->job->payload();
                $parent = is_string($payload[self::PAYLOAD_KEY] ?? null) ? Span::parseTraceparent($payload[self::PAYLOAD_KEY]) : null;
                $this->jobSpan = $this->client->startSpan($name, [
                    'messaging.system' => 'laravel',
                    'messaging.destination.name' => (string) $e->job->getQueue(),
                    'messaging.operation.type' => 'process',
                    'laravel.queue.connection' => $e->connectionName,
                    'laravel.job.attempts' => $e->job->attempts(),
                ], Span::KIND_CONSUMER, true, $sync ? null : $parent);
            }
        }));

        $done = function (string $outcome, ?\Throwable $error, bool $sync): void {
            if ($this->jobSpan !== null) {
                $this->jobSpan->setAttribute('laravel.job.outcome', $outcome)->end($error);
                $this->jobSpan = null;
            }
            if (!$sync) {
                // No shutdown between jobs in a worker: send now.
                $this->client->flush();
            }
        };

        $events->listen(JobProcessed::class, fn (JobProcessed $e) => $this->guard(fn () => $done('processed', null, $e->connectionName === 'sync')));
        $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $e) => $this->guard(function () use ($e): void {
            $this->jobSpan?->setError($e->exception);
        }));
        if (class_exists(JobReleasedAfterException::class)) {
            $events->listen(JobReleasedAfterException::class, fn (JobReleasedAfterException $e) => $this->guard(fn () => $done('released', null, $e->connectionName === 'sync')));
        }
        $events->listen(JobFailed::class, fn (JobFailed $e) => $this->guard(function () use ($e, $done): void {
            // Usually already reported through the exception handler (sent
            // once either way); this catches failures that never reach it.
            ExceptionReporter::capture($this->app, $e->exception);
            if ($this->jobSpan !== null) {
                $done('failed', $e->exception, $e->connectionName === 'sync');
            } elseif ($e->connectionName !== 'sync') {
                $this->client->flush();
            }
        }));
    }

    private function httpClient(Dispatcher $events, bool $crumbs, bool $spans): void
    {
        // laravel/framework only suggests Guzzle; without it the Http client
        // (and its middleware API, which builds Guzzle middleware) cannot run.
        if (!class_exists(RequestSending::class) || !class_exists(\GuzzleHttp\Middleware::class)) {
            return;
        }
        if ($spans && $this->app->bound(HttpFactory::class)) {
            $factory = $this->app->make(HttpFactory::class);
            if ($factory instanceof HttpFactory && method_exists($factory, 'globalRequestMiddleware')) {
                $factory->globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
                    $tp = $this->client->traceparent();

                    return $tp === null || $request->hasHeader('traceparent') ? $request : $request->withHeader('traceparent', $tp);
                });
            }
        }
        if ($spans) {
            $events->listen(RequestSending::class, fn (RequestSending $e) => $this->guard(function () use ($e): void {
                if ($this->client->activeSpan() === null) {
                    return;
                }
                $url = Scrubber::stripQuery($e->request->url());
                $this->httpSpans[$e->request] = $this->client->startSpan($e->request->method() . ' ' . (string) parse_url($url, PHP_URL_HOST), [
                    'http.request.method' => $e->request->method(),
                    'url.full' => $url,
                    'server.address' => (string) parse_url($url, PHP_URL_HOST),
                ], Span::KIND_CLIENT);
            }));
        }
        $events->listen(ResponseReceived::class, fn (ResponseReceived $e) => $this->guard(function () use ($e, $crumbs): void {
            $status = $e->response->status();
            $span = $this->httpSpans[$e->request] ?? null;
            if ($span !== null) {
                unset($this->httpSpans[$e->request]);
                $span->setAttribute('http.response.status_code', $status)->end($status >= 500 ? 'HTTP ' . $status : null);
            }
            if ($crumbs) {
                $this->client->addBreadcrumb([
                    'type' => 'http',
                    'category' => 'http',
                    'level' => $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'),
                    'data' => ['method' => $e->request->method(), 'url' => Scrubber::stripQuery($e->request->url()), 'status_code' => $status],
                ]);
            }
        }));
        $events->listen(ConnectionFailed::class, fn (ConnectionFailed $e) => $this->guard(function () use ($e, $crumbs): void {
            $span = $this->httpSpans[$e->request] ?? null;
            if ($span !== null) {
                unset($this->httpSpans[$e->request]);
                $span->end('connection failed');
            }
            if ($crumbs) {
                $this->client->addBreadcrumb([
                    'type' => 'http', 'category' => 'http', 'level' => 'error',
                    'data' => ['method' => $e->request->method(), 'url' => Scrubber::stripQuery($e->request->url()), 'error' => 'connection failed'],
                ]);
            }
        }));
    }

    private function guard(\Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->client->debug('Laravel listener failed: ' . $e->getMessage());
        }
    }
}
