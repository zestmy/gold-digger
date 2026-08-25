<?php

namespace App\Livewire\Pages;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\TelegramChannel;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Setup
 *
 * The four things that have to be true before a copied signal can become a position,
 * in the order they have to become true, with the state of each read from the system
 * rather than from a checkbox somebody ticked.
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
 * Step three here asks for nothing of the kind. A token this dashboard issued goes into an
 * Expert Advisor you run, and it can be revoked from the page that issued it. The trade-off
 * is real and runs the other way: you supply the terminal.
 */
#[Layout('layouts.app')]
#[Title('Setup - FXSignalPro')]
class Setup extends Component
{
    #[Url]
    public ?int $step = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function steps(): array
    {
        $userId = (int) Auth::id();

        $channels = TelegramChannel::where('user_id', $userId);
        $enabled = (clone $channels)->where('is_enabled', true)->count();
        $known = $channels->count();

        $heartbeat = BotHeartbeat::where('user_id', $userId)->orderByDesc('last_seen_at')->first();
        $settings = BotSettings::where('user_id', $userId)->first();

        $capSet = $settings !== null
            && $settings->ai_trading_enabled
            && $settings->ai_capital_cap !== null
            && (float) $settings->ai_capital_cap > 0;

        return [
            [
                'title' => 'Signal source',
                'done' => $known > 0,
                'detail' => $known > 0
                    ? "{$known} channels visible to the collector."
                    : 'No collector has reported in yet.',
                'blurb' => 'Signals are read by a collector signed in as your own Telegram account, which is what lets it '
                    .'see provider channels rather than only chats a bot was added to. It runs on a machine you choose and '
                    .'keeps its session there, because that session can read every chat you have.',
                'action' => 'Collector setup',
                'route' => 'terminal',
            ],
            [
                'title' => 'Channels',
                'done' => $enabled > 0,
                'detail' => $enabled > 0
                    ? "{$enabled} enabled of {$known}."
                    : ($known > 0 ? 'None enabled yet.' : 'Nothing to choose from yet.'),
                'blurb' => 'Every channel the collector can see is listed, and all of them start switched off. Enable the '
                    .'ones you want traded; the rest keep being recorded so you can compare them before committing. Each '
                    .'channel can also carry its own risk, levels and instrument list.',
                'action' => 'Choose channels',
                'route' => 'signals.channels',
            ],
            [
                'title' => 'Terminal',
                'done' => $heartbeat !== null && $heartbeat->isOnline(),
                'detail' => match (true) {
                    $heartbeat === null => 'No terminal has ever connected.',
                    ! $heartbeat->isOnline() => 'Last seen '.$heartbeat->last_seen_at?->diffForHumans().'.',
                    ! $heartbeat->algo_trading_enabled => 'Online, but Algo Trading is off - every order would be refused.',
                    default => 'Online, carrying '.($heartbeat->resolved_symbol ?? 'an instrument').'.',
                },
                'blurb' => 'Orders are placed by an Expert Advisor running in your own MetaTrader terminal. Download it, '
                    .'paste in a token issued here, and leave the terminal running - on a VPS if you want it trading around '
                    .'the clock. No broker password is stored anywhere, and revoking the token stops it immediately.',
                'action' => 'Connect a terminal',
                'route' => 'terminal',
            ],
            [
                'title' => 'Risk',
                'done' => $capSet,
                'detail' => $capSet
                    ? sprintf(
                        '%s fund at %s%% - %s a trade%s.',
                        number_format((float) $settings->ai_capital_cap, 2),
                        rtrim(rtrim((string) $settings->ai_risk_percentage, '0'), '.'),
                        number_format((float) $settings->ai_capital_cap * (float) $settings->ai_risk_percentage / 100, 2),
                        $settings->ai_max_trades_per_day ? ", max {$settings->ai_max_trades_per_day} a day" : '',
                    )
                    : 'No fund cap set, so nothing can be sized.',
                'blurb' => 'Positions are sized from a fund you set aside rather than from the account balance, so the cap '
                    .'is the most that can ever be lost here. The daily limit bounds how quickly it can be spent. An order '
                    .'too large for what is left is refused rather than rounded up to fit.',
                'action' => 'Set the fund',
                'route' => 'settings',
            ],
        ];
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
        ]);
    }
}
