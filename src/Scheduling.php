<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel;

use Illuminate\Console\Scheduling\Event;
use NaijaCloud\Argus\Argus;

/**
 * Cron check-ins for Laravel's scheduler:
 *
 *     $schedule->command('invoices:send')->daily()->argusMonitor('nightly-invoices');
 *
 * Reports start before the task, then ok or fail from its result. Create the
 * monitor in Argus first; an unknown slug is ignored.
 */
final class Scheduling
{
    public static function monitor(Event $event, string $slug): Event
    {
        return $event
            ->before(static function () use ($slug): void {
                Argus::getClient()?->checkIn($slug, 'start');
            })
            ->onSuccess(static function () use ($slug): void {
                Argus::getClient()?->checkIn($slug, 'ok');
            })
            ->onFailure(static function () use ($event, $slug): void {
                $code = $event->exitCode;
                Argus::getClient()?->checkIn($slug, is_int($code) && $code > 0 ? $code : 'error');
            });
    }
}
