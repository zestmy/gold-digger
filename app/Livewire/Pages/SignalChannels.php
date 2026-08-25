<?php

namespace App\Livewire\Pages;

use App\Models\TelegramChannel;
use App\Services\Telegram\ChannelPerformance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Signal Channels
 *
 * Which providers are on, and what each of them has been worth.
 *
 * ## The switch and the results are on one page on purpose
 *
 * They are the same decision. A channel's numbers exist to answer "should this stay on",
 * and putting that answer one screen away from the control is how a provider keeps
 * trading for a month after its results stopped justifying it. Here, turning one off is
 * a click from the row that argues for it.
 *
 * ## Enabling is the only thing that arms a channel
 *
 * A collector authenticated as a real Telegram account can see every channel that account
 * has ever joined, and it registers all of them so this list reflects what is actually
 * reachable. None of that grants anything: joining a channel to read it must not be the
 * same gesture as trading it, so nothing outside this page ever sets `is_enabled`.
 *
 * ## Small samples are labelled rather than hidden
 *
 * A channel with two wins shows a 100% win rate, and that number is true and useless. The
 * trade count sits beside every rate for exactly that reason, and rows below a handful of
 * closed trades say so in words - dropping them instead would hide a new provider
 * entirely, which is worse than showing one honestly.
 */
#[Layout('layouts.app')]
#[Title('Signal Channels - Gold Digger')]
class SignalChannels extends Component
{
    /** Below this, results are described rather than ranked. */
    private const MEANINGFUL_TRADES = 10;

    #[Url]
    public string $window = 'all';

    public ?int $expanded = null;

    /**
     * The only place `is_enabled` is written outside a migration.
     */
    public function toggle(int $id): void
    {
        $channel = TelegramChannel::where('user_id', Auth::id())->find($id);

        if ($channel === null) {
            return;
        }

        $channel->is_enabled = ! $channel->is_enabled;
        $channel->save();

        $this->dispatch(
            'notify',
            message: $channel->is_enabled
                ? "{$channel->label()} is now a live signal source."
                : "{$channel->label()} will be recorded but not traded.",
            type: $channel->is_enabled ? 'success' : 'info',
        );
    }

    public function expand(?int $channelId): void
    {
        $this->expanded = $this->expanded === $channelId ? null : $channelId;
    }

    public function render(ChannelPerformance $performance)
    {
        $since = match ($this->window) {
            '7d' => Carbon::now()->subDays(7),
            '30d' => Carbon::now()->subDays(30),
            '90d' => Carbon::now()->subDays(90),
            default => null,
        };

        $rows = $performance->forUser((int) Auth::id(), $since);

        return view('livewire.pages.signal-channels', [
            'rows' => $rows,
            // Registered but silent: a channel enabled weeks ago that has posted nothing
            // is a different problem from one posting signals nobody takes.
            'idle' => TelegramChannel::where('user_id', Auth::id())
                ->whereDoesntHave('signals')
                ->orderByDesc('last_message_at')
                ->get(),
            'reasons' => $this->expanded === null
                ? []
                : $performance->declineReasons((int) Auth::id(), $this->expanded),
            'meaningful' => self::MEANINGFUL_TRADES,
        ]);
    }
}
