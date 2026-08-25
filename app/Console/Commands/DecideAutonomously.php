<?php

namespace App\Console\Commands;

use App\Models\BotSettings;
use App\Models\User;
use App\Services\Ai\AutonomousTrader;
use App\Services\Monitoring\AlertNotifier;
use App\Services\Telegram\SignalExecutor;
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

        foreach (User::query()->orderBy('id')->cursor() as $user) {
            $settings = BotSettings::where('user_id', $user->id)->first();

            if ($settings === null || ! $settings->ai_autonomous) {
                continue;
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
                    $this->announce($notifier, $decision, (string) $symbol, $result['note']);
                }
            }
        }

        $this->newLine();
        $this->info("{$considered} considered, {$opened} opened.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function announce(AlertNotifier $notifier, array $decision, string $symbol, string $note): void
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
        );
    }
}
