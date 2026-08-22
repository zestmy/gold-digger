<?php

namespace App\Console\Commands;

use App\Models\TradeCommand;
use Illuminate\Console\Command;

/**
 * Sweep Expired Commands
 *
 * `trade_commands.status` has always had an `expired` value and nothing ever wrote it.
 * `scopeClaimable` refuses to hand out a lapsed command, so execution was never wrong - but
 * the row stayed `pending` for ever, the queue filled with work that would never happen, and
 * "what became of that command" had no answer in the row itself.
 *
 * Safe to run as often as you like: it only touches rows already past their own expiry, and
 * an executor could not have claimed them anyway.
 */
class SweepExpiredCommands extends Command
{
    protected $signature = 'commands:sweep';

    protected $description = 'Mark queued commands that passed their expiry as expired';

    public function handle(): int
    {
        $swept = TradeCommand::sweepExpired();

        $this->info($swept === 0
            ? 'No expired commands.'
            : "Marked {$swept} command(s) expired.");

        return self::SUCCESS;
    }
}
