<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Telegram\PositionManager;
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

        foreach (User::query()->orderBy('id')->cursor() as $user) {
            foreach ($manager->manage($user) as $action) {
                $this->line("  #{$user->id} <options=bold>{$action}</>");
                $acted++;
            }
        }

        $this->info($acted === 0 ? 'Nothing needed protecting.' : "{$acted} action(s) queued.");

        return self::SUCCESS;
    }
}
