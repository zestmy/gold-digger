<?php

namespace App\Console\Commands;

use App\Models\SignalOutcome;
use App\Models\User;
use App\Services\Outcomes\StopWidthReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * What the signals would have done under a different stop.
 *
 * The question the first month of outcomes could not answer: is the stop too tight, or
 * the entry too early? This re-walks every decided signal's bars with the stop at a range
 * of multiples and prints the win rate and expectancy at each. See StopWidthReport.
 */
class ReportStopWidth extends Command
{
    protected $signature = 'signals:stop-width
        {--user= : User id or email; every user when omitted}
        {--source=ai : ai or copied}
        {--since= : Only signals started on or after this date}
        {--multiples= : Comma-separated multiples of the recorded stop, e.g. 1,1.5,2}';

    protected $description = 'Re-score decided signals under wider and narrower stops';

    public function handle(StopWidthReport $report): int
    {
        $source = (string) $this->option('source');

        if (! in_array($source, [SignalOutcome::SOURCE_AI, SignalOutcome::SOURCE_COPIED], true)) {
            $this->error('--source must be ai or copied.');

            return self::FAILURE;
        }

        $since = $this->option('since') ? Carbon::parse((string) $this->option('since'), 'UTC') : null;
        $multiples = $this->multiples();

        foreach ($this->users() as $user) {
            $result = $report->forUser($user->id, $since, $source, $multiples);

            $this->newLine();
            $this->line(sprintf('<options=bold>%s</> - %s signals, %d scorable, %d unscorable (bars pruned)', $user->email, $source, $result['scorable'], $result['unscorable']));

            if ($result['scorable'] === 0) {
                continue;
            }

            $this->table(
                ['Stop ×', 'n', 'Won', 'Lost', 'Neither', 'Win rate', 'Expectancy R', 'Bars to TP1', 'TP2 reached', 'TP3 reached'],
                array_map(fn (array $row) => [
                    number_format($row['multiple'], 2).($row['multiple'] === 1.0 ? ' (as traded)' : ''),
                    $row['n'],
                    $row['won'],
                    $row['lost'],
                    $row['expired'],
                    $row['win_rate'] === null ? '—' : $row['win_rate'].'%',
                    $row['expectancy_r'] === null ? '—' : sprintf('%+.2f', $row['expectancy_r']),
                    $row['avg_tp1_bars'] === null ? '—' : $row['avg_tp1_bars'],
                    $row['tp2_rate'] === null ? '—' : $row['tp2_rate'].'%',
                    $row['tp3_rate'] === null ? '—' : $row['tp3_rate'].'%',
                ], $result['rows']),
            );
        }

        $this->newLine();
        $this->line('<fg=gray>Targets move with the stop - 1R / 2R / 3R of the width on each row - so every row is the trade the strategy would have made at that width. Position size follows the stop, so R is the same money throughout.</>');

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, User>
     */
    private function users(): iterable
    {
        $who = $this->option('user');

        if ($who === null || $who === '') {
            return User::query()->orderBy('id')->cursor();
        }

        $user = is_numeric($who) ? User::find((int) $who) : User::where('email', $who)->first();

        return $user === null ? [] : [$user];
    }

    /**
     * @return array<int, float>
     */
    private function multiples(): array
    {
        $raw = (string) $this->option('multiples');

        if ($raw === '') {
            return StopWidthReport::MULTIPLES;
        }

        $values = array_values(array_filter(array_map('floatval', explode(',', $raw)), fn (float $m) => $m > 0.0));
        sort($values);

        return $values ?: StopWidthReport::MULTIPLES;
    }
}
