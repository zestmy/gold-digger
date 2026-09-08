<?php

namespace App\Console\Commands;

use App\Models\Strategy;
use App\Models\User;
use App\Support\StarterStrategies;
use Illuminate\Console\Command;

/**
 * Add Starter Strategies
 *
 * Gives an existing account the starter strategies it registered too early to receive.
 *
 * ## Why this exists
 *
 * `UserObserver` fires once, on registration. When the starter set grows - as it did when
 * EURUSD and GBPUSD joined gold - every account created before that release keeps the set it
 * was given, and no migration reaches them, because a strategy is a user's own configuration
 * rather than schema. The symptom is the one that prompted this: a signals feed showing a
 * single instrument, on a system whose strategy layer has been multi-symbol for a while.
 *
 * ## Why it matches on symbol, not name
 *
 * A strategy the owner renamed is still that instrument's strategy. Matching on the name
 * would hand them a second gold scalp for having called the first one something else.
 * Matching on symbol means the command is safe to run repeatedly and never competes with a
 * strategy someone is already trading.
 *
 * That also means it will not add a preset for a symbol the account already covers, even if
 * that existing strategy looks nothing like the preset. Deciding otherwise would be editing
 * someone's live parameters from a console command, which is not this command's business.
 *
 * ## Why the new rows are inactive
 *
 * Same reason the observer's are: see `StarterStrategies`. This command adds strategies to
 * review, not trades to place. Nothing generates a signal until the Strategies page activates
 * it, and nothing reaches the market until that instrument is also in the terminal's
 * `BaseSymbols` list and has pushed bars.
 */
class AddStarterStrategies extends Command
{
    protected $signature = 'strategies:add-starters
                            {--user= : Only this user id; defaults to every account}
                            {--dry-run : Report what would be added without writing anything}';

    protected $description = 'Add any missing starter strategies to existing accounts';

    public function handle(): int
    {
        $users = User::query()
            ->when($this->option('user'), fn ($q, $id) => $q->where('id', (int) $id))
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->warn('No matching accounts.');

            return self::SUCCESS;
        }

        $definitions = StarterStrategies::definitions();
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $added = 0;

        foreach ($users as $user) {
            // One query per account rather than per preset, and compared case-insensitively
            // because a broker-flavoured row could have been entered as "eurusd".
            $covered = Strategy::query()
                ->where('user_id', $user->id)
                ->pluck('symbol')
                ->map(fn ($symbol) => strtoupper((string) $symbol))
                ->all();

            foreach ($definitions as $definition) {
                $symbol = (string) $definition['symbol'];

                if (in_array(strtoupper($symbol), $covered, true)) {
                    $rows[] = [$user->id, $user->email, $symbol, 'already covered'];

                    continue;
                }

                if (! $dryRun) {
                    Strategy::create(['user_id' => $user->id] + $definition);
                }

                $rows[] = [$user->id, $user->email, $symbol, $dryRun ? 'would add' : 'added (inactive)'];
                $added++;
            }
        }

        $this->table(['User', 'Email', 'Symbol', 'Outcome'], $rows);

        if ($added === 0) {
            $this->info('Every account already covers every starter symbol. Nothing to do.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("{$added} strategies would be added. Run without --dry-run to write them.");

            return self::SUCCESS;
        }

        $this->info("{$added} strategies added, all inactive.");
        $this->line('  Next: activate them on the Strategies page, and add the symbols to the');
        $this->line("  terminal's BaseSymbols input so their bars arrive.");

        return self::SUCCESS;
    }
}
