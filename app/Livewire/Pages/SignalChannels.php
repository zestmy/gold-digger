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

    /**
     * A collector reports every channel its account has ever joined, which for a real
     * account is well over a hundred. A list that long with no filter is not a list of
     * choices, it is a haystack - and the one you want is the one you cannot find.
     */
    #[Url]
    public string $search = '';

    /** Hide the ones that have never posted anything, which is most of them. */
    #[Url]
    public bool $onlyActive = false;

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

        if ($this->search !== '') {
            $needle = mb_strtolower($this->search);

            $rows = $rows->filter(
                fn (array $row) => str_contains(mb_strtolower((string) $row['label']), $needle)
                    || str_contains(mb_strtolower((string) $row['channel']?->username), $needle)
                    || str_contains((string) $row['channel']?->chat_id, $needle),
            )->values();
        }

        return view('livewire.pages.signal-channels', [
            'rows' => $rows,
            // Registered but silent: a channel enabled weeks ago that has posted nothing
            // is a different problem from one posting signals nobody takes.
            'idle' => $this->onlyActive ? collect() : TelegramChannel::where('user_id', Auth::id())
                ->whereDoesntHave('signals')
                ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                    $q->where('title', 'like', '%'.$this->search.'%')
                        ->orWhere('username', 'like', '%'.$this->search.'%')
                        ->orWhere('chat_id', 'like', '%'.$this->search.'%');
                }))
                // Enabled ones first: a channel you have armed is the one you most need to
                // be able to find again.
                ->orderByDesc('is_enabled')
                ->orderByDesc('last_message_at')
                ->limit($this->search === '' ? 25 : 100)
                ->get(),
            'idleTotal' => TelegramChannel::where('user_id', Auth::id())
                ->whereDoesntHave('signals')
                ->count(),
            'reasons' => $this->expanded === null
                ? []
                : $performance->declineReasons((int) Auth::id(), $this->expanded),
            'meaningful' => self::MEANINGFUL_TRADES,
        ]);
    }
}
