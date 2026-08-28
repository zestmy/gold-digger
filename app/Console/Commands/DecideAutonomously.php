<?php

namespace App\Console\Commands;

use App\Models\BotSettings;
use App\Models\User;
use App\Services\Ai\AutonomousTrader;
use App\Services\Monitoring\AlertNotifier;
use App\Services\Telegram\SignalExecutor;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantSweep;
use Illuminate\Console\Command;

/**
 * Form an opinion, and act on it if the measurements agree.
 *
 * Run on a schedule rather than on every closed bar: a decision costs a model call per
 * instrument, and one taken every five minutes on the same slowly-changing picture is
 * mostly the same decision paid for repeatedly.
 */
class DecideAutonomously extends Command
{
    protected $signature = 'ai:decide {--symbol= : Consider only this instrument}
                            {--dry : Decide and report without placing anything}';

    protected $description = 'Let the AI decide whether to open a position of its own';

    public function handle(AutonomousTrader $trader, SignalExecutor $executor, AlertNotifier $notifier): int
    {
        $considered = 0;
        $opened = 0;

        // TenantSweep runs each account as itself and keeps going when one of them throws.
        // Both matter here: a scheduled command is the one place where "whose request is
        // this" has no answer unless it is stated, and a model that timed out for one tenant
        // used to stop every tenant behind them from being considered at all.
        $result = app(TenantSweep::class)->each(
            User::query()->orderBy('id')->cursor(),
            function (User $user) use ($trader, $executor, $notifier, &$considered, &$opened) {
                [$considered, $opened] = $this->decideFor($user, $trader, $executor, $notifier, $considered, $opened);
            },
        );

        $this->newLine();
        $this->info("{$considered} considered, {$opened} opened.");

        if ($result['failed'] > 0) {
            $this->warn("{$result['failed']} account(s) could not be considered; each is on /logs.");
        }

        return self::SUCCESS;
    }

    /**
     * One tenant's autonomous pass.
     *
     * @return array{0: int, 1: int} considered and opened, carried through
     */
    private function decideFor(User $user, AutonomousTrader $trader, SignalExecutor $executor, AlertNotifier $notifier, int $considered, int $opened): array
    {
        $settings = BotSettings::where('user_id', $user->id)->first();

        if ($settings === null || ! $settings->ai_autonomous) {
            return [$considered, $opened];
        }

        $symbols = $this->option('symbol')
            ? [(string) $this->option('symbol')]
            : (array) ($settings->ai_autonomous_symbols ?? []);

        foreach ($symbols as $symbol) {
            $considered++;

            $decision = $trader->consider($user, (string) $symbol);

            $this->line(sprintf(
                '  %-10s <options=bold>%s</>',
                $symbol,
                $decision['traded'] ? 'TRADE' : 'no trade',
            ));
            $this->line('     <fg=gray>'.$decision['why'].'</>');

            if (! $decision['traded'] || $this->option('dry')) {
                continue;
            }

            // Through the copier's executor, so the fund cap, the daily limit and
            // every other gate apply exactly as they do to a copied signal.
            $result = $executor->execute($decision['signal']);

            $this->line('     <fg=gray>'.$result['note'].'</>');

            if ($result['ok']) {
                $opened++;
                $this->announce($notifier, $user, $decision, (string) $symbol, $result['note']);
            }
        }

        return [$considered, $opened];
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function announce(AlertNotifier $notifier, User $user, array $decision, string $symbol, string $note): void
    {
        $signal = $decision['signal'];

        $notifier->announce(
            sprintf('AI opened %s %s', strtoupper((string) $signal->direction), $symbol),
            implode("\n", [
                $note,
                'Its reasoning: '.$decision['why'],
                'Measured evidence: '.$signal->review_reasoning,
                'This was the system\'s own decision, not a copied signal.',
            ]),
            '🟣',
            ['telegram_signal_id' => $signal->id, 'symbol' => $symbol],
            // Whose position was opened, so the message reaches them rather than the
            // operator's channel. A trade placed while nobody was watching is exactly the
            // message that must not go to the wrong person.
            $user,
        );
    }
}
