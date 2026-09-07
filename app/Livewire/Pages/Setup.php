<?php

namespace App\Livewire\Pages;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\TelegramChannel;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Connection
 *
 * How a signal becomes a trade on the subscriber's own MT5: the four things that have to
 * be true first, in the order they have to become true, with the state of each read from
 * the system rather than from a checkbox somebody ticked.
 *
 * ## Why the steps are derived, not stored
 *
 * A wizard that remembers how far you got is lying the moment anything changes underneath
 * it - a revoked token, a channel switched off, a terminal that stopped beating. Each step
 * here asks the question it is about, every render. Going back is therefore not a
 * navigation feature; it is what the page does by itself when something breaks.
 *
 * ## Where this deliberately differs from the copiers it resembles
 *
 * The hosted services ask for your broker password at this point, because their cloud logs
 * into your account as you. That is the only way to trade MT5 without something running
 * beside the terminal, and it means a company holds a credential that can trade your
 * account.
 *
 * The terminal step here asks for nothing of the kind. A token this dashboard issued goes
 * into an Expert Advisor you run, and it can be revoked from the page that issued it. The
 * trade-off is real and runs the other way: you supply the terminal.
 *
 * ## The switch at the top
 *
 * Auto-trade on or off is the one control somebody comes back to this page for after the
 * four steps are done, so it sits in the header rather than four cards down on the risk
 * tab. It is the same flag the risk tab's master switch flips - `BotSettings.is_active` -
 * and flipping it here is no more or less ceremonious than flipping it there.
 */
#[Layout('layouts.app')]
#[Title('Auto-Trade - FXSignalPro')]
class Setup extends Component
{
    #[Url]
    public ?int $step = null;

    /**
     * Flip auto-trade, exactly as the risk tab's master switch does.
     *
     * `firstOrCreate` rather than `botSettings->update`: a user created outside
     * registration has no row until something writes one, and a switch that cannot be
     * turned on because it was never turned on is a locked door.
     */
    public function toggleAutoTrade(): void
    {
        $settings = BotSettings::firstOrCreate(['user_id' => Auth::id()]);

        $settings->update(['is_active' => ! $settings->is_active]);

        $this->dispatch(
            'notify',
            message: $settings->is_active ? 'Auto-trade is on.' : 'Auto-trade is off.',
            type: 'success',
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function steps(): array
    {
        $userId = (int) Auth::id();

        $account = BrokerAccount::where('user_id', $userId)->where('is_active', true)->first();
        $accounts = BrokerAccount::where('user_id', $userId)->count();

        $hasToken = BotToken::where('user_id', $userId)->exists();
        $heartbeat = BotHeartbeat::where('user_id', $userId)->orderByDesc('last_seen_at')->first();

        $channels = TelegramChannel::where('user_id', $userId);
        $enabled = (clone $channels)->where('is_enabled', true)->count();
        $known = $channels->count();

        $settings = BotSettings::where('user_id', $userId)->first();

        $capSet = $settings !== null
            && $settings->ai_trading_enabled
            && $settings->ai_capital_cap !== null
            && (float) $settings->ai_capital_cap > 0;

        return [
            [
                'title' => 'Broker account',
                'done' => $account !== null,
                'detail' => match (true) {
                    $account !== null => sprintf(
                        '%s on %s, %s.',
                        $account->label,
                        $account->server,
                        $account->is_demo ? 'demo' : 'live',
                    ),
                    $accounts > 0 => "{$accounts} added, none marked active.",
                    default => 'No broker account added yet.',
                },
                'blurb' => 'The MT5 account your terminal is logged into. The dashboard needs its number and '
                    .'server to tell one terminal\'s fills from another\'s, and nothing more - no password is '
                    .'asked for, because nothing here logs in as you.',
                'action' => 'Broker accounts',
                'route' => 'broker-accounts',
                'links' => [],
            ],
            [
                'title' => 'Terminal',
                'done' => $heartbeat !== null && $heartbeat->isOnline(),
                'detail' => match (true) {
                    $heartbeat === null && ! $hasToken => 'No token issued, no terminal has connected.',
                    $heartbeat === null => 'Token issued; waiting for the terminal\'s first report.',
                    ! $heartbeat->isOnline() => 'Last seen '.$heartbeat->last_seen_at?->diffForHumans().'.',
                    ! $heartbeat->algo_trading_enabled => 'Online, but Algo Trading is off - every order would be refused.',
                    default => 'Online, carrying '.($heartbeat->resolved_symbol ?? 'an instrument').'.',
                },
                'blurb' => 'Orders are placed by an Expert Advisor running in your own MetaTrader terminal. Download it, '
                    .'paste in a token issued here, and leave the terminal running - on a VPS if you want it trading around '
                    .'the clock. No broker password is stored anywhere, and revoking the token stops it immediately.',
                'action' => 'Terminal',
                'route' => 'terminal',
                // Offered only once a token exists: the archive is useless without one to
                // paste into it, and offering the download first sends people the wrong way.
                'links' => $hasToken ? [['Download EA', 'terminal.download']] : [],
            ],
            [
                'title' => 'Signal sources',
                'done' => $enabled > 0,
                'detail' => match (true) {
                    $enabled > 0 => "{$enabled} enabled of {$known}.",
                    $known > 0 => "{$known} visible, none enabled yet.",
                    default => 'No provider channels visible yet.',
                },
                'blurb' => 'Every channel the collector can see is listed under Providers, and all of them start '
                    .'switched off. Enable the ones you want traded; the rest keep being recorded so you can compare '
                    .'them before committing. Each channel can also carry its own risk, levels and instrument list.',
                'action' => 'Providers',
                'route' => 'signals.channels',
                'links' => [],
            ],
            [
                'title' => 'Risk',
                'done' => $capSet,
                'detail' => $settings === null
                    ? 'No risk settings saved yet.'
                    : self::riskSummary($settings),
                'blurb' => 'Positions are sized from a fund you set aside rather than from the account balance, so the '
                    .'cap is the most that can ever be lost here. The daily stop bounds how quickly it can be spent. An '
                    .'order too large for what is left is refused rather than rounded up to fit.',
                'action' => 'Risk & filters',
                'route' => 'settings',
                'links' => [],
            ],
        ];
    }

    /**
     * "x% per trade · y% daily stop · fund $z", or "· no fund set" while there is none.
     *
     * The three numbers somebody checks before switching auto-trade on, in the order
     * they matter: how much one trade can lose, how much a day can, how much in total.
     */
    public static function riskSummary(BotSettings $settings): string
    {
        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

        $fund = $settings->ai_capital_cap !== null && (float) $settings->ai_capital_cap > 0
            ? 'fund $'.number_format((float) $settings->ai_capital_cap, 2)
            : 'no fund set';

        return sprintf(
            '%s%% per trade · %s%% daily stop · %s',
            $pct($settings->risk_percentage ?? 1),
            $pct($settings->max_daily_loss_percentage ?? 5),
            $fund,
        );
    }

    public function render()
    {
        $steps = $this->steps();

        // The first thing that is not true yet. Not where you left off - a wizard that
        // remembers its own progress is wrong the moment a token is revoked.
        $current = null;

        foreach ($steps as $index => $step) {
            if (! $step['done']) {
                $current = $index;
                break;
            }
        }

        return view('livewire.pages.setup', [
            'steps' => $steps,
            'current' => $this->step !== null && isset($steps[$this->step]) ? $this->step : $current,
            'ready' => $current === null,
            'autoTrade' => (bool) BotSettings::where('user_id', Auth::id())->value('is_active'),
        ]);
    }
}
