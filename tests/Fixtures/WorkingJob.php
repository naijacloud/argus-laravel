<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

final class WorkingJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        DB::select('select 1 as one');
        \NaijaCloud\Argus\Argus::logs()->info('job ran');
    }
}
