<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Tests;

use NaijaCloud\Argus\Argus as CoreArgus;
use NaijaCloud\Argus\Client;
use NaijaCloud\Argus\Laravel\ArgusServiceProvider;
use NaijaCloud\Argus\Laravel\Http\TraceRequests;

final class ServiceProviderTest extends TestCase
{
    public function testTheClientIsBuiltFromConfigAndShared(): void
    {
        $client = $this->app->make(Client::class);
        self::assertTrue($client->isEnabled());
        self::assertSame($client, $this->app->make('argus'));
        self::assertSame($client, CoreArgus::getClient(), 'the core static API uses the same client');
        self::assertSame('shop@2.0.0', $client->options->release);
        self::assertSame('testing', $client->options->environment, 'APP_ENV when ARGUS_ENVIRONMENT is unset');
        self::assertSame('Laravel', $client->options->serviceName, 'APP_NAME by default');
        self::assertFalse($client->options->registerHandlers, "Laravel's own handlers stay in charge");
    }

    public function testConfigIsMergedAndPublishable(): void
    {
        self::assertSame(['up'], config('argus.tracing.ignore_paths'));
        $paths = ArgusServiceProvider::pathsToPublish(ArgusServiceProvider::class, 'argus-config');
        self::assertCount(1, $paths);
        self::assertStringEndsWith('config/argus.php', (string) array_values($paths)[0]);
        self::assertFileExists((string) array_key_first($paths));
    }

    public function testTheLogChannelIsDefinedWithoutEditingLoggingConfig(): void
    {
        self::assertSame(['driver' => 'argus', 'level' => 'debug'], config('logging.channels.argus'));
    }

    public function testTheMiddlewareIsGlobalAndAliased(): void
    {
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        self::assertTrue($kernel->hasMiddleware(TraceRequests::class));
        self::assertSame(TraceRequests::class, $this->app['router']->getMiddleware()['argus.trace'] ?? null);
    }

    public function testTheFacadeForwardsToTheClient(): void
    {
        \Argus::captureMessage('via the facade');
        \Argus::checkIn('facade-job');
        self::assertTrue(\Argus::isOn('new-checkout', 'u1'));
        $this->flushArgus();
        self::assertSame('via the facade', self::events()[0]['message']);
        self::assertCount(1, self::requests('/v1/cron/facade-job'));
    }

    public function testPackageDiscoveryIsDeclared(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        self::assertSame([ArgusServiceProvider::class], $composer['extra']['laravel']['providers']);
        self::assertSame(\NaijaCloud\Argus\Laravel\Facades\Argus::class, $composer['extra']['laravel']['aliases']['Argus']);
        self::assertSame('^0.1', $composer['require']['naijacloud/argus'], 'the published package requires the core from Packagist');
    }
}
