<?php

namespace App\Console\Commands;

use App\Models\Strategy;
use App\Models\Trade;
use App\Services\Strategy\TradeManager;
use Illuminate\Console\Command;

/**
 * Manage Trades
 *
 * Runs the position-management pass by hand against whatever candles are stored.
 *
 * The counterpart to `signals:generate`, and useful for the same reason: management
 * normally fires from a candle push, so the interesting question - "why has TP1 not been
 * taken when price clearly went through it" - can only be asked while a terminal is
 * attached and pushing. This asks it on demand.
 *
 * It queues real commands. There is no dry run here, unlike `signals:generate`: every
 * action is idempotent on a fixed key, so running it repeatedly cannot produce a second
 * close, and a "what would you do" mode that queued nothing would answer a different
 * question from the one being asked.
 */
class ManageTrades extends Command
{
    protected $signature = 'trades:manage
                            {--strategy= : Only this strategy id}
                            {--account= : Broker account id whose series to read}';

    protected $description = 'Check open positions against the take-profit ladder and exit rules, and queue what they need';

    public function handle(TradeManager $manager): int
    {
        $accountId = $this->option('account') !== null ? (int) $this->option('account') : null;

        $strategies = Strategy::query()
            ->where('is_active', true)
            ->when($this->option('strategy'), fn ($q, $id) => $q->where('id', (int) $id))
            ->get();

        if ($strategies->isEmpty()) {
            $this->warn('No active strategies.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($strategies as $strategy) {
            $open = Trade::where('strategy_id', $strategy->id)
                ->whereIn('status', ['open', 'partially_closed'])
                ->count();

            $actions = $manager->manage($strategy, $accountId);

            if ($actions === []) {
                $rows[] = [$strategy->id, $strategy->name, $open, '-', 'nothing to do'];

                continue;
            }

            foreach ($actions as $action) {
                $rows[] = [$strategy->id, $strategy->name, $open, $action['trade_id'], $action['action']];
            }
        }

        $this->table(['Strategy', 'Name', 'Open', 'Trade', 'Queued'], $rows);

        return self::SUCCESS;
    }
}
