<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Telegram\PositionManager;
use App\Support\Tenancy\TenantSweep;
use Illuminate\Console\Command;

/**
 * Look after open copied positions.
 *
 * Separate from the strategy's own trade management because the two measure differently -
 * pips it chose against R a stranger chose - and because a fault in either must not stop
 * the other from protecting a live position.
 */
class ProtectCopiedPositions extends Command
{
    protected $signature = 'copier:protect';

    protected $description = 'Trail, break even and lock profit on copied positions';

    public function handle(PositionManager $manager): int
    {
        $acted = 0;

        // Through TenantSweep rather than a plain foreach: one account whose broker answered
        // strangely used to abort the command, and every tenant with a higher id went
        // unprotected until the cause was found. A stop that is not trailed because somebody
        // else's data is malformed is the worst kind of silent failure this system has.
        $result = app(TenantSweep::class)->each(
            User::query()->orderBy('id')->cursor(),
            function (User $user) use ($manager, &$acted) {
                foreach ($manager->manage($user) as $action) {
                    $this->line("  #{$user->id} <options=bold>{$action}</>");
                    $acted++;
                }
            },
        );

        $this->info($acted === 0 ? 'Nothing needed protecting.' : "{$acted} action(s) queued.");

        if ($result['failed'] > 0) {
            // Named here as well as reported, because a scheduled run nobody watches is
            // exactly where a partial sweep would otherwise pass for a complete one.
            $this->warn("{$result['failed']} account(s) could not be swept; each is on /logs.");
        }

        return self::SUCCESS;
    }
}
