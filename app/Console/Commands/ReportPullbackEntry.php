<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Outcomes\PullbackEntryReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * What the signals would have done had the entry waited for a pullback.
 *
 * The stop-width report said the stop is not the problem. This asks whether the entry
 * is: a limit resting some fraction of the stop behind the signal price, filled if price
 * comes back within a window, scored from there. See PullbackEntryReport.
 */
class ReportPullbackEntry extends Command
{
    protected $signature = 'signals:pullback-entry
        {--user= : User id or email; every user when omitted}
        {--since= : Only signals started on or after this date}
        {--depths= : Comma-separated fractions of the stop, e.g. 0,0.25,0.5; "zone" for the signal\'s own zone}
        {--wait=12 : Bars a resting limit waits before the setup is called stale}';

    protected $description = 'Re-score decided AI signals with the entry waiting for a pullback';

    public function handle(PullbackEntryReport $report): int
    {
        $since = $this->option('since') ? Carbon::parse((string) $this->option('since'), 'UTC') : null;
        $depths = $this->depths();
        $wait = max(1, (int) $this->option('wait'));

        foreach ($this->users() as $user) {
            $result = $report->forUser($user->id, $since, $depths, $wait);

            $this->newLine();
            $this->line(sprintf('<options=bold>%s</> - %d scorable, %d unscorable (bars pruned), limits wait %d bars', $user->email, $result['scorable'], $result['unscorable'], $result['wait_bars']));

            if ($result['scorable'] === 0) {
                continue;
            }

            $this->table(
                ['Pullback', 'n', 'Filled', 'Missed', 'Won', 'Lost', 'Neither', 'Fill rate', 'Win rate', 'Exp. per signal', 'Exp. per fill', 'Risk kept'],
                array_map(fn (array $row) => [
                    $row['depth'] === PullbackEntryReport::ZONE ? "signal's zone" : ($row['depth'] === 0.0 ? 'none (as traded)' : number_format((float) $row['depth'], 2).' of stop'),
                    $row['n'],
                    $row['filled'],
                    $row['unfilled'],
                    $row['won'],
                    $row['lost'],
                    $row['expired'],
                    $row['fill_rate'] === null ? '—' : $row['fill_rate'].'%',
                    $row['win_rate'] === null ? '—' : $row['win_rate'].'%',
                    $row['expectancy_r'] === null ? '—' : sprintf('%+.2f', $row['expectancy_r']),
                    $row['expectancy_filled_r'] === null ? '—' : sprintf('%+.2f', $row['expectancy_filled_r']),
                    $row['avg_risk_share'] === null ? '—' : number_format($row['avg_risk_share'] * 100, 0).'%',
                ], $result['rows']),
            );
        }

        $this->newLine();
        $this->line('<fg=gray>The stop stays where the signal put it, so a filled pullback risks less; the 1R / 2R / 3R ladder is measured off that shorter risk. A limit that never fills is a missed trade worth 0R and is counted in the per-signal expectancy.</>');

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
     * @return array<int, float|string>|null
     */
    private function depths(): ?array
    {
        $raw = trim((string) $this->option('depths'));

        if ($raw === '') {
            return null;
        }

        $depths = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);

            if ($part === PullbackEntryReport::ZONE) {
                $depths[] = PullbackEntryReport::ZONE;
            } elseif (is_numeric($part) && (float) $part >= 0.0) {
                $depths[] = (float) $part;
            }
        }

        return $depths ?: null;
    }
}
