<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Tests;

use Illuminate\Support\Facades\Log;

final class LoggingTest extends TestCase
{
    public function testTheArgusChannelSendsToArgusLogs(): void
    {
        Log::channel('argus')->info('order {id} placed', ['id' => 'ord_81', 'password' => 'hunter2']);
        Log::channel('argus')->warning('slow');
        $this->flushArgus();
        $recs = self::logRecords();
        self::assertCount(2, $recs);
        self::assertSame('order ord_81 placed', $recs[0]['body']['stringValue']);
        self::assertSame('[Filtered]', $recs[0]['attrs']['password']);
        self::assertSame('WARN', $recs[1]['severityText']);
        self::assertSame('Laravel', self::attrs((array) json_decode(self::requests('/v1/logs')[0]['body'], true)['resourceLogs'][0]['resource']['attributes'])['service.name']);
    }

    public function testTheChannelRespectsItsLevel(): void
    {
        config(['logging.channels.argus.level' => 'error']);
        Log::channel('argus')->info('below');
        Log::channel('argus')->error('above');
        $this->flushArgus();
        self::assertSame(['above'], array_map(static fn ($r) => $r['body']['stringValue'], self::logRecords()));
    }

    public function testInAStackAlongsideFiles(): void
    {
        config(['logging.channels.both' => ['driver' => 'stack', 'channels' => ['single', 'argus']]]);
        Log::channel('both')->notice('to both');
        $this->flushArgus();
        self::assertCount(1, self::logRecords());
    }

    public function testLogMessagesBecomeBreadcrumbs(): void
    {
        Log::info('before the error');
        \Argus::captureMessage('after');
        $this->flushArgus();
        $crumbs = self::events()[0]['breadcrumbs']['values'];
        self::assertSame('before the error', $crumbs[array_key_last($crumbs)]['message']);
    }
}
