<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Tests;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use NaijaCloud\Argus\Client;
use NaijaCloud\Argus\Laravel\Http\TraceRequests;

final class NoDsnTest extends TestCase
{
    protected bool $withDsn = false;

    protected function defineRoutes($router): void
    {
        Route::get('/boom', static fn () => throw new \RuntimeException('boom'));
        Route::get('/fine', static fn () => 'fine');
    }

    public function testWithoutADsnTheAppRunsAsBeforeAndNothingIsSent(): void
    {
        self::assertFalse($this->app->make(Client::class)->isEnabled());
        $this->get('/fine')->assertOk()->assertSee('fine');
        $this->get('/boom')->assertStatus(500);
        Log::channel('argus')->error('nobody listening');
        \Argus::captureMessage('nope');
        self::assertFalse($this->app->make(\Illuminate\Contracts\Http\Kernel::class)->hasMiddleware(TraceRequests::class));
        self::assertSame([], self::$server->requests());
    }

    public function testArgusTestExplainsTheMissingDsn(): void
    {
        $this->artisan('argus:test')
            ->expectsOutputToContain('No DSN. Add ARGUS_DSN to .env')
            ->assertExitCode(1);
    }

    public function testScheduledTasksUsingTheMacroStillLoad(): void
    {
        $event = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)->call(static fn () => null)->argusMonitor('job');
        $event->run($this->app);
        self::assertSame([], self::$server->requests());
    }
}
