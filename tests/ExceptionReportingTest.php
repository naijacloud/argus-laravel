<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

final class ExceptionReportingTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/orders/{order}', static function (string $order) {
            throw new \RuntimeException("order {$order} exploded");
        })->name('orders.show');
        Route::get('/invalid', static function () {
            throw ValidationException::withMessages(['email' => 'required']);
        });
        Route::get('/missing', static fn () => abort(404));
        Route::get('/reported', static function () {
            report(new \LogicException('reported by hand'));

            return 'ok';
        });
        Route::get('/logged', static function () {
            $e = new \DomainException('logged and reported');
            report($e);
            Log::error('it broke', ['exception' => $e]);

            return 'ok';
        });
    }

    public function testAnExceptionInARouteIsReportedWithTheRouteAndUser(): void
    {
        $this->actingAs(new GenericUser(['id' => 81, 'email' => 'ada@example.com', 'remember_token' => null]));
        $this->get('/orders/ord_81?token=secret', ['Authorization' => 'Bearer abcdefghijklmn', 'Cookie' => 'laravel_session=abc'])->assertStatus(500);

        $events = self::events();
        self::assertCount(1, $events);
        $e = $events[0];
        self::assertSame('php', $e['platform']);
        self::assertSame('argus.php', $e['sdk']['name']);
        self::assertSame('error', $e['level']);
        self::assertSame('order ord_81 exploded', $e['exception']['values'][0]['value']);
        self::assertSame('GET /orders/{order}', $e['transaction'], 'the culprit is the route pattern');
        self::assertSame('orders.show', $e['tags']['route']);
        self::assertSame(['id' => '81'], $e['user'], 'email only with send_default_pii');
        self::assertSame('http://localhost/orders/ord_81', $e['request']['url'], 'no query string');
        self::assertSame('[Filtered]', $e['request']['headers']['authorization'], 'a sensitive header name filters the whole value');
        self::assertArrayNotHasKey('cookie', $e['request']['headers']);
        self::assertSame('testing', $e['environment']);
        self::assertSame('shop@2.0.0', $e['release']);
        $frames = $e['exception']['values'][0]['stacktrace']['frames'];
        $vendor = array_filter($frames, static fn ($f) => str_contains($f['abs_path'], '/vendor/laravel/'));
        self::assertNotEmpty($vendor);
        foreach ($vendor as $f) {
            self::assertFalse($f['in_app'], 'framework frames are not the app');
        }
    }

    public function testDontReportIsRespected(): void
    {
        $this->get('/invalid')->assertStatus(302);
        $this->get('/missing')->assertStatus(404);
        $this->flushArgus();
        self::assertSame([], self::requests('/api/'));
    }

    public function testReportHelperAndLogChannelSendOnce(): void
    {
        config(['logging.default' => 'argus']);
        $this->get('/reported')->assertOk();
        $this->get('/logged')->assertOk();
        $this->flushArgus();
        $values = array_map(static fn ($e) => $e['exception']['values'][0]['value'], self::events());
        self::assertSame(['reported by hand', 'logged and reported'], $values, 'report() and a log with the same exception: one event');
    }
}
