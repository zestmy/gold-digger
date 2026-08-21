<?php

namespace App\Console\Commands;

use App\Models\BotHeartbeat;
use App\Models\Candle;
use App\Models\Strategy;
use App\Services\Strategy\SignalGenerator;
use App\Services\Strategy\StrategyEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Generate Signals
 *
 * Runs the strategy layer by hand against whatever candles are already stored.
 *
 * Signal generation normally fires from a candle push, which means it only happens while a
 * terminal is attached and pushing. That is a poor place to debug from: the interesting
 * question is usually "why did this bar not produce a signal", and the answer is buried in
 * a request that already returned. This command asks the same question on demand, and
 * `--explain` prints the readings the decision was made from.
 *
 * It writes exactly what the API path writes - signals, and commands for signals that pass
 * every filter. Use --dry-run to see what would happen without queueing anything.
 */
class GenerateSignals extends Command
{
    protected $signature = 'signals:generate
                            {--strategy= : Only this strategy id}
                            {--account= : Broker account id whose series to read}
                            {--explain : Print the indicator readings behind each decision}
                            {--dry-run : Evaluate and report without writing signals or commands}';

    protected $description = 'Evaluate active strategies against stored candles and enqueue any resulting entries';

    public function handle(SignalGenerator $generator): int
    {
        $accountId = $this->option('account') !== null ? (int) $this->option('account') : null;

        $strategies = Strategy::query()
            ->where('is_active', true)
            ->when($this->option('strategy'), fn ($q, $id) => $q->where('id', (int) $id))
            ->get();

        if ($strategies->isEmpty()) {
            $this->warn('No active strategies. A strategy must have is_active set before it generates anything.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($strategies, $accountId);
        }

        $rows = [];

        foreach ($strategies as $strategy) {
            $signal = $generator->generate($strategy, $accountId);

            if ($signal === null) {
                $rows[] = [$strategy->id, $strategy->name, '-', 'no setup on the latest bar', '-'];

                continue;
            }

            $rows[] = [
                $strategy->id,
                $strategy->name,
                $signal->direction,
                $signal->skip_reason ?? 'queued',
                $signal->suggested_lot_size ?? '-',
            ];

            if ($this->option('explain')) {
                $this->line("  <fg=gray>signal #{$signal->id} features: ".json_encode($signal->features).'</>');
            }
        }

        $this->table(['Strategy', 'Name', 'Direction', 'Outcome', 'Lots'], $rows);

        return self::SUCCESS;
    }

    /**
     * Report what the rules see without persisting anything.
     *
     * Uses the evaluator directly rather than the generator, so no signal row and no
     * command can be written. It therefore reports only whether the *entry rules* fired -
     * the risk filters are the generator's job and are not consulted here.
     *
     * @param  Collection<int, Strategy>  $strategies
     */
    private function dryRun(Collection $strategies, ?int $accountId): int
    {
        $evaluator = new StrategyEvaluator;

        foreach ($strategies as $strategy) {
            $heartbeat = BotHeartbeat::where('user_id', $strategy->user_id)
                ->orderByDesc('last_seen_at')
                ->first();

            $symbol = $heartbeat?->resolved_symbol ?: $strategy->symbol;
            $account = $accountId ?? $heartbeat?->broker_account_id;

            $entry = Candle::recentSeries($account, $symbol, $strategy->timeframe_entry, StrategyEvaluator::LOOKBACK_BARS);
            $trend = Candle::recentSeries($account, $symbol, $strategy->timeframe_trend, StrategyEvaluator::LOOKBACK_BARS);

            $this->line("<info>{$strategy->name}</info> (#{$strategy->id}) {$symbol} {$strategy->timeframe_entry}/{$strategy->timeframe_trend}");
            $this->line('  bars stored: '.count($entry).' entry, '.count($trend).' trend');

            $setup = $evaluator->evaluate($strategy, $entry, $trend);

            if ($setup === null) {
                $this->line('  <comment>no setup on the latest closed bar</comment>');

                continue;
            }

            $this->line("  <info>{$setup->direction}</info> at {$setup->entryPrice}, ADX ".round($setup->adx, 2).', ATR '.round($setup->atr, 5));
            $this->line('  <fg=gray>'.json_encode($setup->features).'</>');
        }

        return self::SUCCESS;
    }
}
