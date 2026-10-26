<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Http;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use NaijaCloud\Argus\Client;
use NaijaCloud\Argus\Laravel\Integrations\Context;
use NaijaCloud\Argus\Span;
use Symfony\Component\HttpFoundation\Response;

/**
 * One server span per request, named after its route ("GET /orders/{order}")
 * once routing has matched, continuing the caller's W3C traceparent. Added to
 * the global middleware automatically; also available as `argus.trace`.
 *
 * terminate() runs after Laravel has sent the response (and, under PHP-FPM,
 * after fastcgi_finish_request()), so flushing there never delays the visitor.
 */
final class TraceRequests
{
    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $client = $this->client();
        if ($client === null || $this->ignored($request)) {
            return $next($request);
        }
        $span = null;
        try {
            $client->continueTrace($request->headers->get('traceparent'));
            $span = $client->startSpan($request->getMethod() . ' ' . '/' . ltrim($request->path(), '/'), [
                'http.request.method' => $request->getMethod(),
                'url.full' => $request->url(),
                'url.scheme' => $request->getScheme(),
                'server.address' => $request->getHost(),
                'user_agent.original' => (string) $request->userAgent(),
            ], Span::KIND_SERVER, true);
            Context::request($client, $request);
        } catch (\Throwable) {
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $span?->end($e);

            throw $e;
        }

        if ($span !== null) {
            try {
                $this->finish($client, $request, $span, $response);
            } catch (\Throwable) {
            }
        }

        return $response;
    }

    public function terminate(Request $request, mixed $response): void
    {
        $client = $this->client();
        if ($client === null) {
            return;
        }
        $client->flush();
        // Octane keeps the process (and the client) between requests.
        if (isset($_SERVER['LARAVEL_OCTANE'])) {
            $client->clearScope();
        }
    }

    private function finish(Client $client, Request $request, Span $span, mixed $response): void
    {
        $name = Context::routeName($request);
        $route = $request->route();
        if ($name !== null && is_object($route) && method_exists($route, 'uri')) {
            $span->setName($name);
            $span->setAttribute('http.route', '/' . ltrim((string) $route->uri(), '/'));
            $client->setTransaction($name);
        }
        if (is_object($route) && method_exists($route, 'getName') && is_string($route->getName())) {
            $span->setAttribute('laravel.route.name', $route->getName());
        }
        Context::user($this->app, $client);
        $status = $response instanceof Response ? $response->getStatusCode() : 200;
        $span->setAttribute('http.response.status_code', $status);
        $span->end($status >= 500 ? 'HTTP ' . $status : null);
    }

    private function ignored(Request $request): bool
    {
        $paths = $this->app->make(Config::class)->get('argus.tracing.ignore_paths', []);
        foreach (is_array($paths) ? $paths : [] as $path) {
            if (is_string($path) && $request->is(ltrim($path, '/'))) {
                return true;
            }
        }

        return false;
    }

    private function client(): ?Client
    {
        $client = $this->app->make(Client::class);

        return $client->isEnabled() ? $client : null;
    }
}
