<?php

namespace App\Console\Commands;

use App\Models\TelegramSignal;
use App\Services\Telegram\SignalExecutor;
use Illuminate\Console\Command;

/**
 * Queue orders for approved signals.
 *
 * Separate from review so approval and execution are distinct acts, and so the step that
 * places real orders can be run deliberately - or scheduled, once you trust it.
 */
class ExecuteTelegramSignals extends Command
{
    protected $signature = 'telegram:execute {--limit=5 : Most signals to act on in one pass}';

    protected $description = 'Queue orders for approved Telegram signals';

    public function handle(SignalExecutor $executor): int
    {
        $approved = TelegramSignal::where('review_status', TelegramSignal::REVIEW_APPROVED)
            ->where('execution_status', TelegramSignal::EXEC_NONE)
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($approved->isEmpty()) {
            $this->info('Nothing approved and waiting.');

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($approved as $signal) {
            $result = $executor->execute($signal);
            $queued += $result['ok'] ? 1 : 0;

            $this->line(sprintf(
                '  #%d %s %s -> <options=bold>%s</>',
                $signal->id,
                $signal->symbol,
                strtoupper((string) $signal->direction),
                strtoupper($result['status']),
            ));
            $this->line('     <fg=gray>'.$result['note'].'</>');
        }

        $this->newLine();
        $this->info(sprintf('%d approved, %d queued.', $approved->count(), $queued));

        return self::SUCCESS;
    }
}
