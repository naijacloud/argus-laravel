<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Laravel\Console;

use Illuminate\Console\Command;
use NaijaCloud\Argus\Client;
use NaijaCloud\Argus\Dsn;

/** `php artisan argus:test`: sends one event and says whether Argus took it. */
final class TestCommand extends Command
{
    protected $signature = 'argus:test {--dsn= : Test this DSN instead of the configured one}';

    protected $description = 'Send a test event to Argus and report whether it was accepted';

    public function handle(Client $configured): int
    {
        $dsnOption = $this->option('dsn');
        $client = is_string($dsnOption) && $dsnOption !== ''
            ? new Client(['dsn' => $dsnOption, 'environment' => $configured->options->environment, 'release' => $configured->options->release, 'register_handlers' => false, 'register_shutdown' => false])
            : $configured;

        if (!$client->isEnabled()) {
            if ($client->options->dsn !== null && Dsn::parse($client->options->dsn) === null) {
                $this->error('The DSN is set but is not a valid DSN. It looks like https://<key>@argus-in.naijacloud.com/<id>.');
            } else {
                $this->error('No DSN. Add ARGUS_DSN to .env (copy it from your source in Argus), then run this again.');
                $this->line('If config is cached, run php artisan config:clear first.');
            }

            return self::FAILURE;
        }

        /** @var Dsn $dsn */
        $dsn = $client->dsn;
        $this->line("Sending a test event to {$dsn->origin} (source {$dsn->projectId}, environment {$client->options->environment})...");

        $id = $client->captureMessage('Argus test event from argus-laravel', 'info', ['tags' => ['argus.test' => 'yes']]);
        $client->log('info', 'Argus test log from argus-laravel');
        $client->flush(10_000);

        $status = $client->getTransport()->lastStatus('errors');
        if ($status !== null && $status >= 200 && $status < 300) {
            $this->info("Argus accepted the test event ({$id}). Open your source in Argus to see it.");

            return self::SUCCESS;
        }
        $this->error(match (true) {
            $status === null => 'Argus could not be reached. Check the network from this machine to ' . $dsn->origin . '.',
            $status === 401, $status === 403 => "Argus refused the key ({$status}). Copy the DSN again from the source's settings in Argus.",
            $status === 429 => 'Argus is rate limiting this source (429). The workspace may be over its monthly allowance.',
            default => "Argus answered {$status}.",
        });

        return self::FAILURE;
    }
}
