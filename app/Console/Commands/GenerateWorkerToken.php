<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Mints the shared secret the hosted session worker authenticates with.
 *
 * Not issued by anything and not registered anywhere: the dashboard and the worker simply
 * have to hold the same string. This exists so that "make one up" does not become a short
 * one somebody typed, and so the value is generated where it will be used rather than
 * pasted through a chat window or a ticket on its way there.
 */
class GenerateWorkerToken extends Command
{
    protected $signature = 'telegram:worker-token';

    protected $description = 'Generate a TELEGRAM_WORKER_TOKEN for the hosted session worker';

    public function handle(): int
    {
        // Str::random draws from a CSPRNG. 64 characters is well past guessing, and this
        // is typed by nobody - it goes into two environment files and is never seen again.
        $token = 'gdw_'.Str::random(64);

        $this->newLine();
        $this->line("  <fg=green>{$token}</>");
        $this->newLine();
        $this->line('  Set this <options=bold>identical</> value in both places:');
        $this->line('    1. the dashboard .env          TELEGRAM_WORKER_TOKEN=');
        $this->line('    2. the worker environment file  TELEGRAM_WORKER_TOKEN=');
        $this->newLine();
        $this->comment('  It reaches every tenant\'s Telegram session. Treat it like the database');
        $this->comment('  password: 600 on disk, out of the repository, and rotated by changing it');
        $this->comment('  in both places and restarting the worker.');
        $this->newLine();

        return self::SUCCESS;
    }
}
