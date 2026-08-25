<?php

namespace App\Console\Commands;

use App\Models\Strategy;
use App\Services\Ai\StrategyImprovement;
use Illuminate\Console\Command;

/**
 * Ask a model for candidate parameters, then measure them.
 *
 * ## The division of labour
 *
 * `backtest:optimise` searches a grid you specify. That is exhaustive within the grid and
 * completely blind about which grid to search - it will happily spend a thousand runs on
 * ATR periods while every recent setup dies on ADX.
 *
 * This asks a model where to look, and then submits the answer to exactly the same
 * walk-forward validation. The model never sees a result and never gets a vote. What comes
 * out is a table of measured out-of-sample performance with a sentence of reasoning
 * attached to each row, and the reasoning is there to be judged against the number beside
 * it rather than instead of it.
 *
 * The work itself lives in `StrategyImprovement`, shared with the queued job behind the
 * dashboard's Improver page. A console run and a dashboard run that disagreed about the
 * baseline or the bar window would produce two answers to one question, and the one you
 * would believe is whichever you saw last.
 *
 * ## It changes nothing
 *
 * No parameter is written. A proposer that could edit the strategy it is proposing about
 * would be marking its own homework - and BACKTESTING.md exists because these settings
 * used to be opinions.
 */
class ImproveStrategy extends Command
{
    protected $signature = 'strategy:improve
                            {strategy? : Strategy id. Defaults to the only active one}
                            {--account= : Broker account id the candles belong to}
                            {--symbol= : Series to measure. Defaults to the resolved symbol on the heartbeat}
                            {--bars=20000 : Most recent bars to measure over}
                            {--folds=4 : Walk-forward folds}
                            {--min-trades=10 : Fold results below this are not counted}
                            {--from= : Only use bars from this date}
                            {--to= : Only use bars up to this date}
                            {--spread= : Override the spread assumption, in pips}
                            {--slippage=0.3 : Adverse slippage per market fill, in pips}
                            {--commission=0 : Commission per lot per round trip}
                            {--balance=1000 : Starting balance for the simulation}';

    protected $description = 'Have a model propose strategy parameters, then walk-forward validate them';

    public function handle(StrategyImprovement $improver): int
    {
        $strategy = $this->resolveStrategy();

        if ($strategy === null) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->line("<options=bold>{$strategy->name}</> · measuring the baseline, then the proposals…");

        $bar = $this->output->createProgressBar();
        $bar->start();

        $result = $improver->run($strategy, [
            'account' => $this->option('account') !== null ? (int) $this->option('account') : null,
            'symbol' => $this->option('symbol'),
            'bars' => (int) $this->option('bars'),
            'folds' => (int) $this->option('folds'),
            'min_trades' => (int) $this->option('min-trades'),
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'spread' => $this->option('spread') !== null ? (float) $this->option('spread') : null,
            'slippage' => (float) $this->option('slippage'),
            'commission' => (float) $this->option('commission'),
            'balance' => (float) $this->option('balance'),
        ], fn () => $bar->advance());

        $bar->finish();
        $this->newLine(2);

        if (! $result['ok']) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }

        $this->line("On {$result['symbol']} · {$result['bars']} bars · {$result['from']} to {$result['to']}");
        $this->newLine();

        // The verdict before the table, not after it.
        //
        // WalkForwardReport already refuses to read anything into a thin sample - and the
        // first version of this command printed the numbers without it, which produced a
        // tidy baseline-versus-proposed comparison off nine trades that looked exactly
        // like a finding. A table is far more persuasive than a caveat underneath it, so
        // the caveat goes first and the numbers arrive already qualified.
        if ($result['thin']) {
            $this->warn('  '.$result['verdict']);
            $this->newLine();
        }

        $this->line('<options=bold>Out-of-sample, stitched across folds</>');
        $this->table(['Metric', 'Baseline', 'Proposed'], [
            ['Trades', $result['baseline']['trades'] ?? 0, $result['proposed']['trades'] ?? 0],
            ['Net P&L', $result['baseline']['net_pnl'] ?? 0, $result['proposed']['net_pnl'] ?? 0],
            ['Win rate', ($result['baseline']['win_rate'] ?? 0).'%', ($result['proposed']['win_rate'] ?? 0).'%'],
            ['Expectancy', $result['baseline']['expectancy'] ?? 0, $result['proposed']['expectancy'] ?? 0],
            ['Profitable folds', $this->folds($result['baseline']), $this->folds($result['proposed'])],
        ]);

        $this->newLine();
        $this->line("<options=bold>What was proposed, and why</> <fg=gray>({$result['model']})</>");

        foreach ($result['proposals'] as $i => $proposal) {
            $changes = [];

            foreach ($proposal['parameters'] as $name => $value) {
                $changes[] = "{$name}=".rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
            }

            $this->line(sprintf('  %d. %s', $i + 1, implode('  ', $changes)));
            $this->line('     <fg=gray>'.$proposal['rationale'].'</>');
        }

        $this->newLine();
        $this->line('<options=bold>Before you apply anything</>');

        if ($result['thin']) {
            $this->line('  <options=bold>Nothing here supports a change.</> The sample is too small to prefer any of');
            $this->line('  these over what you already run, including the ones that look better.');
            $this->newLine();
        }

        // The "Proposed" column is the best combination *per fold*, stitched. Selecting a
        // winner from several candidates and then reporting the winner's score flatters it,
        // which is exactly the bias walk-forward exists to limit and does not remove.
        $this->line('  The proposed column is the best candidate in each fold, stitched together.');
        $this->line('  Picking a winner from '.count($result['proposals']).' and then quoting its score flatters it.');
        $this->newLine();
        $this->line('  Nothing has been changed. Confirm a candidate independently:');
        $this->line('    php artisan backtest:optimise '.$strategy->id.' --param="name=value,value"');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $oos
     */
    private function folds(array $oos): string
    {
        return ($oos['folds_profitable'] ?? 0).' of '.($oos['folds_tested'] ?? 0);
    }

    private function resolveStrategy(): ?Strategy
    {
        if ($this->argument('strategy')) {
            $strategy = Strategy::find((int) $this->argument('strategy'));

            if ($strategy === null) {
                $this->error('No strategy with id '.$this->argument('strategy').'.');
            }

            return $strategy;
        }

        $active = Strategy::where('is_active', true)->get();

        if ($active->count() === 1) {
            return $active->first();
        }

        $this->error($active->isEmpty()
            ? 'No active strategy. Name one explicitly.'
            : 'Several active strategies. Name the one you mean.');

        return null;
    }
}
