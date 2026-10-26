<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Tests;

use Illuminate\Contracts\Foundation\Application;
use NaijaCloud\Argus\Argus;
use NaijaCloud\Argus\Laravel\ArgusServiceProvider;
use NaijaCloud\Argus\Laravel\Facades\Argus as ArgusFacade;
use NaijaCloud\Argus\Laravel\Tests\Support\MockServer;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * A Laravel app (Testbench) with the package auto-registered and ARGUS_DSN
 * pointing at the local stand-in for Argus ingest.
 */
abstract class TestCase extends Testbench
{
    protected static MockServer $server;

    /** The router scenario this test's DSN selects. */
    protected string $scenario = 'ok';

    /** Set false to boot the app with no DSN at all. */
    protected bool $withDsn = true;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$server = MockServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        self::$server->reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Argus::setClient(null);
    }

    /** @param Application $app */
    protected function getPackageProviders($app): array
    {
        return [ArgusServiceProvider::class];
    }

    /** @param Application $app */
    protected function getPackageAliases($app): array
    {
        return ['Argus' => ArgusFacade::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('argus.dsn', $this->withDsn ? self::$server->dsn($this->scenario) : null);
        $config->set('argus.release', 'shop@2.0.0');
        // Backoff is recorded, not slept, so retries cannot slow the suite.
        $config->set('argus.sleeper', static function (): void {
        });
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('queue.default', 'sync');
    }

    /**
     * @return list<array{scenario:string,method:string,path:string,uri:string,headers:array<string,string>,body:string}>
     */
    protected static function requests(string $prefix): array
    {
        return self::$server->requests($prefix);
    }

    /** @return list<array<string,mixed>> the event payloads sent */
    protected static function events(): array
    {
        $out = [];
        foreach (self::requests('/api/') as $r) {
            $lines = explode("\n", rtrim($r['body'], "\n"));
            self::assertCount(3, $lines);
            $out[] = (array) json_decode($lines[2], true);
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    protected static function spans(): array
    {
        $out = [];
        foreach (self::requests('/v1/traces') as $r) {
            $d = (array) json_decode($r['body'], true);
            foreach ($d['resourceSpans'][0]['scopeSpans'][0]['spans'] as $s) {
                $s['attrs'] = self::attrs($s['attributes']);
                $out[] = $s;
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    protected static function logRecords(): array
    {
        $out = [];
        foreach (self::requests('/v1/logs') as $r) {
            $d = (array) json_decode($r['body'], true);
            foreach ($d['resourceLogs'][0]['scopeLogs'][0]['logRecords'] as $rec) {
                $rec['attrs'] = self::attrs($rec['attributes']);
                $out[] = $rec;
            }
        }

        return $out;
    }

    /**
     * @param list<array{key:string,value:array<string,mixed>}> $attrs
     * @return array<string,mixed>
     */
    protected static function attrs(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $a) {
            $out[$a['key']] = array_values($a['value'])[0];
        }

        return $out;
    }

    protected function flushArgus(): void
    {
        self::assertTrue($this->app->make(\NaijaCloud\Argus\Client::class)->flush(5000));
    }
}
