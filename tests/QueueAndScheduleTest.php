<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use NaijaCloud\Argus\Laravel\Tests\Fixtures\FailingJob;
use NaijaCloud\Argus\Laravel\Tests\Fixtures\WorkingJob;

final class QueueAndScheduleTest extends TestCase
{
    public function testAFailedJobIsReportedWithItsJobTags(): void
    {
        try {
            FailingJob::dispatch();
        } catch (\RuntimeException) {
        }
        $this->flushArgus();
        $events = self::events();
        self::assertCount(1, $events, 'once, though the handler and JobFailed both see it');
        self::assertSame('invoice job failed', $events[0]['exception']['values'][0]['value']);
        self::assertSame(FailingJob::class, $events[0]['tags']['queue.job']);
        self::assertSame('sync', $events[0]['tags']['queue.connection']);
        $job = array_values(array_filter(self::spans(), static fn ($s) => $s['kind'] === 5));
        self::assertCount(1, $job);
        self::assertSame(2, $job[0]['status']['code']);
        self::assertSame('failed', $job[0]['attrs']['laravel.job.outcome']);
    }

    public function testAJobRunsInsideASpanWithItsQueriesAndLogs(): void
    {
        WorkingJob::dispatch();
        $this->flushArgus();
        $spans = self::spans();
        $job = array_values(array_filter($spans, static fn ($s) => $s['kind'] === 5))[0];
        self::assertSame(WorkingJob::class, $job['name']);
        self::assertSame('processed', $job['attrs']['laravel.job.outcome']);
        $query = array_values(array_filter($spans, static fn ($s) => $s['kind'] === 3))[0];
        self::assertSame($job['spanId'], $query['parentSpanId']);
        self::assertSame($job['traceId'], self::logRecords()[0]['traceId']);
    }

    public function testAWorkerJobContinuesTheDispatchingTraceAndFlushes(): void
    {
        $client = $this->app->make(\NaijaCloud\Argus\Client::class);
        $client->setTag('leftover', 'from the last job');
        $job = $this->createStub(Job::class);
        $job->method('resolveName')->willReturn('App\\Jobs\\SendInvoice');
        $job->method('getQueue')->willReturn('default');
        $job->method('attempts')->willReturn(1);
        $job->method('payload')->willReturn(['argus_traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
        event(new JobProcessing('redis', $job));
        \NaijaCloud\Argus\Argus::logs()->info('inside worker job');
        event(new JobProcessed('redis', $job));
        // flushed by JobProcessed, not by this test
        $span = self::spans()[0];
        self::assertSame('App\\Jobs\\SendInvoice', $span['name']);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['traceId']);
        self::assertSame('00f067aa0ba902b7', $span['parentSpanId']);
        self::assertCount(1, self::requests('/v1/logs'));

        \Argus::captureMessage('next');
        $this->flushArgus();
        self::assertArrayNotHasKey('leftover', self::events()[0]['tags'], 'a worker job starts with a clean scope');
    }

    public function testDispatchCarriesTheTraceIntoThePayload(): void
    {
        $client = $this->app->make(\NaijaCloud\Argus\Client::class);
        $span = $client->startSpan('request', [], \NaijaCloud\Argus\Span::KIND_SERVER, true);
        // createPayload() is what every driver calls when a job is pushed.
        $queue = $this->app['queue']->connection('sync');
        $payload = json_decode((fn () => $this->createPayload(new WorkingJob(), 'default'))->call($queue), true);
        $span->end();
        self::assertSame($span->traceparent(), $payload['argus_traceparent']);
    }

    public function testTheSchedulerMacroReportsCheckIns(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $schedule->call(static fn () => null)->argusMonitor('nightly-invoices')->run($this->app);
        $failing = $schedule->call(static function (): never {
            throw new \RuntimeException('cron failed');
        })->argusMonitor('nightly-invoices');
        try {
            $failing->run($this->app);
        } catch (\RuntimeException) {
        }
        $paths = array_map(static fn ($r) => $r['path'], self::requests('/v1/cron/'));
        self::assertSame([
            '/v1/cron/nightly-invoices/start',
            '/v1/cron/nightly-invoices',
            '/v1/cron/nightly-invoices/start',
            '/v1/cron/nightly-invoices/1',
        ], $paths);
    }

    public function testArgusTestSendsAnEventAndReportsSuccess(): void
    {
        $this->artisan('argus:test')
            ->expectsOutputToContain('Argus accepted the test event')
            ->assertExitCode(0);
        self::assertSame('Argus test event from argus-laravel', self::events()[0]['message']);
    }

    public function testArgusTestReportsARefusedKey(): void
    {
        $this->artisan('argus:test', ['--dsn' => self::$server->dsn('r401')])
            ->expectsOutputToContain('Argus refused the key (401)')
            ->assertExitCode(1);
    }

    public function testArgusTestReportsAnInvalidDsn(): void
    {
        $this->artisan('argus:test', ['--dsn' => 'not-a-dsn'])
            ->expectsOutputToContain('is not a valid DSN')
            ->assertExitCode(1);
    }
}
