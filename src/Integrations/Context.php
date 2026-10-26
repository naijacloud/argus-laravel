<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Integrations;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use NaijaCloud\Argus\Client;

/**
 * The request, route and user an event belongs to, read from Laravel at the
 * moment it is reported.
 *
 * @internal
 */
final class Context
{
    public static function apply(Application $app, Client $client): void
    {
        if (!$app->runningInConsole() || $app->runningUnitTests()) {
            $request = $app->bound('request') ? $app->make('request') : null;
            if ($request instanceof Request && $request->getMethod() !== '') {
                self::request($client, $request);
            }
        }
        self::user($app, $client);
    }

    public static function request(Client $client, Request $request): void
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            if (strtolower((string) $name) === 'cookie') {
                continue; // never sent; the rest is scrubbed by the core
            }
            $headers[(string) $name] = implode(', ', array_map('strval', (array) $values));
        }
        $client->setRequest(['method' => $request->getMethod(), 'url' => $request->url(), 'headers' => $headers]);
        $name = self::routeName($request);
        if ($name !== null) {
            $client->setTransaction($name);
            $route = $request->route();
            if (is_object($route) && method_exists($route, 'getName') && is_string($route->getName())) {
                $client->setTag('route', $route->getName());
            }
        }
    }

    /** "GET /orders/{order}", or null before routing. */
    public static function routeName(Request $request): ?string
    {
        $route = $request->route();
        if (!is_object($route) || !method_exists($route, 'uri')) {
            return null;
        }

        return $request->getMethod() . ' /' . ltrim((string) $route->uri(), '/');
    }

    public static function user(Application $app, Client $client): void
    {
        try {
            if (!$app->bound('auth')) {
                return;
            }
            // hasUser() avoids a database query just to report an error.
            $guard = $app->make(AuthFactory::class)->guard();
            if (!$guard->hasUser()) {
                return;
            }
            $user = $guard->user();
            if ($user === null) {
                return;
            }
            $data = ['id' => (string) $user->getAuthIdentifier()];
            if ($client->options->sendDefaultPii) {
                foreach (['email', 'username', 'name'] as $field) {
                    $value = $user->{$field} ?? null;
                    if (is_string($value) && $value !== '') {
                        $data[$field === 'name' ? 'username' : $field] ??= $value;
                    }
                }
            }
            $client->setUser($data);
        } catch (\Throwable) {
        }
    }
}
