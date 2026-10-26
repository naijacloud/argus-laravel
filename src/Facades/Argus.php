<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use NaijaCloud\Argus\Client;

/**
 * The Argus client, as a facade (aliased as `Argus`).
 *
 * @method static string captureException(\Throwable $error, array<string,mixed> $context = [])
 * @method static string captureMessage(string $message, string $level = 'info', array<string,mixed> $context = [])
 * @method static void setUser(?array<string,mixed> $user)
 * @method static void setTag(string $key, string|int|float|bool $value)
 * @method static void setTags(array<string,string> $tags)
 * @method static void setContext(string $name, ?array<string,mixed> $context)
 * @method static void addBreadcrumb(array<string,mixed> $crumb)
 * @method static void log(string $severity, string|\Stringable $message, array<string,mixed> $attributes = [])
 * @method static void count(string $name, int|float $value = 1, array<string,mixed> $attributes = [])
 * @method static void gauge(string $name, int|float $value, array<string,mixed> $attributes = [], ?string $unit = null)
 * @method static void distribution(string $name, int|float $value, array<string,mixed> $attributes = [], ?string $unit = null)
 * @method static mixed trace(string $name, callable $fn, array<string,mixed> $attributes = [])
 * @method static \NaijaCloud\Argus\Span startSpan(string $name, array<string,mixed> $attributes = [], int $kind = 1, bool $activate = false)
 * @method static ?string traceparent()
 * @method static void checkIn(string $slug, string|int $status = 'ok', ?string $output = null)
 * @method static mixed withMonitor(string $slug, callable $job)
 * @method static bool isOn(string $key, string $subject, bool $fallback = false, array<string,mixed> $attributes = [])
 * @method static string variant(string $key, string $subject, string $fallback, array<string,mixed> $attributes = [])
 * @method static array<string,string> evaluateFlags(string $subject, array<string,mixed> $attributes = [])
 * @method static bool flush(?int $timeoutMs = null)
 * @method static bool isEnabled()
 *
 * @see Client
 */
final class Argus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
