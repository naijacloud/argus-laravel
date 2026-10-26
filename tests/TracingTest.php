<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

final class TracingTest extends TestCase
{
    /**
     * Http::fake() needs Guzzle, which laravel/framework only suggests. The
     * Laravel 10 CI cell runs without it, which is what proves the package
     * boots in an app that has no Guzzle.
     */
    private function requireGuzzle(): void
    {
        if (!class_exists(\GuzzleHttp\Client::class)) {
            self::markTestSkipped('guzzlehttp/guzzle is not installed');
        }
    }

    protected function defineRoutes($router): void
    {
        Route::get('/users/{user}', static function (string $user) {
            DB::select('select ? as id', [$user]);
            \NaijaCloud\Argus\Argus::logs()->info('loaded user');

            return ['id' => $user];
        })->name('users.show');
        Route::get('/broken', static fn () => response('down', 503));
        Route::get('/up', static fn () => 'up');
        Route::get('/outgoing', static function () {
            Http::get('https://api.paystack.co/transaction/verify/ref_1?key=secret');

            return 'ok';
        });
    }

    public function testARequestBecomesAServerSpanNamedAfterItsRoute(): void
    {
        $this->get('/users/7', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])->assertOk();

        $spans = self::spans();
        $server = array_values(array_filter($spans, static fn ($s) => $s['kind'] === 2));
        self::assertCount(1, $server);
        $req = $server[0];
        self::assertSame('GET /users/{user}', $req['name']);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $req['traceId'], "the caller's trace continues");
        self::assertSame('00f067aa0ba902b7', $req['parentSpanId']);
        self::assertSame('/users/{user}', $req['attrs']['http.route']);
        self::assertSame('users.show', $req['attrs']['laravel.route.name']);
        self::assertSame('GET', $req['attrs']['http.request.method']);
        self::assertSame('200', $req['attrs']['http.response.status_code']);
        self::assertSame(['code' => 1], $req['status']);

        $query = array_values(array_filter($spans, static fn ($s) => $s['kind'] === 3));
        self::assertCount(1, $query, 'a child span per query');
        self::assertSame('SELECT', $query[0]['name']);
        self::assertSame($req['spanId'], $query[0]['parentSpanId']);
        self::assertSame('sqlite', $query[0]['attrs']['db.system']);
        self::assertSame('select ? as id', $query[0]['attrs']['db.query.text'], 'bindings are never sent');

        $logs = self::logRecords();
        self::assertSame($req['traceId'], $logs[0]['traceId'], 'logs inside the request carry its trace');
    }

    public function testA5xxResponseMarksTheSpanFailed(): void
    {
        $this->get('/broken')->assertStatus(503);
        $span = self::spans()[0];
        self::assertSame(2, $span['status']['code']);
        self::assertSame('HTTP 503', $span['status']['message']);
    }

    public function testIgnoredPathsAreNotTraced(): void
    {
        $this->get('/up')->assertOk();
        self::assertSame([], self::requests('/v1/traces'));
    }

    public function testOutgoingHttpCallsCarryTraceparentAndBecomeChildSpans(): void
    {
        $this->requireGuzzle();
        Http::fake(['*' => Http::response(['status' => true], 200)]);
        $this->get('/outgoing')->assertOk();

        Http::assertSent(static fn ($request) => preg_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $request->header('traceparent')[0] ?? '') === 1);
        $client = array_values(array_filter(self::spans(), static fn ($s) => $s['kind'] === 3));
        self::assertCount(1, $client);
        self::assertSame('GET api.paystack.co', $client[0]['name']);
        self::assertSame('https://api.paystack.co/transaction/verify/ref_1', $client[0]['attrs']['url.full'], 'no query string');
        self::assertSame('200', $client[0]['attrs']['http.response.status_code']);
    }

    public function testBreadcrumbsRecordQueriesAndHttpCalls(): void
    {
        $this->requireGuzzle();
        Http::fake(['*' => Http::response('', 500)]);
        Route::get('/crumbs', static function () {
            DB::select('select 1');
            Http::get('https://example.com/a?token=x');
            throw new \RuntimeException('with crumbs');
        });
        $this->get('/crumbs')->assertStatus(500);
        $crumbs = self::events()[0]['breadcrumbs']['values'];
        $cats = array_column($crumbs, 'category');
        self::assertContains('db.query', $cats);
        $http = array_values(array_filter($crumbs, static fn ($c) => $c['category'] === 'http'))[0];
        self::assertSame('https://example.com/a', $http['data']['url']);
        self::assertSame(500, $http['data']['status_code']);
        self::assertSame('error', $http['level']);
    }
}
