<?php

namespace App\Livewire\Pages;

use App\Models\BotSettings;
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
#[Title('Signal Channels - FXSignalPro')]
class SignalChannels extends Component
{
    /** A private chat named by its owner, waiting to be turned into a chat id. */
    public string $privateUsername = '';

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

    /** The channel whose settings are open for editing, if any. */
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

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

    /**
     * Open one channel's overrides.
     *
     * Blank fields mean inherit, and the placeholders show what would be inherited - so
     * the difference between "5% because I chose it" and "5% because the account says so"
     * stays visible rather than collapsing into the same-looking input.
     */
    public function edit(int $id): void
    {
        $channel = TelegramChannel::where('user_id', Auth::id())->find($id);

        if ($channel === null) {
            return;
        }

        $this->editing = $id;
        $this->form = [
            'risk_percentage' => $channel->risk_percentage === null ? '' : (string) $channel->risk_percentage,
            'copier_levels' => $channel->copier_levels ?? '',
            'max_trades_per_day' => $channel->max_trades_per_day === null ? '' : (string) $channel->max_trades_per_day,
            'min_confluence' => $channel->min_confluence === null ? '' : (string) $channel->min_confluence,
            'symbols_allow' => implode(', ', (array) ($channel->symbols_allow ?? [])),
            'symbols_deny' => implode(', ', (array) ($channel->symbols_deny ?? [])),
            'read_images' => $channel->read_images === null ? '' : ($channel->read_images ? '1' : '0'),
        ];
    }

    public function savePolicy(): void
    {
        $channel = TelegramChannel::where('user_id', Auth::id())->find($this->editing);

        if ($channel === null) {
            return;
        }

        $this->validate([
            'form.risk_percentage' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'form.copier_levels' => ['nullable', 'in:provider,strategy'],
            'form.max_trades_per_day' => ['nullable', 'integer', 'min:0', 'max:50'],
            'form.min_confluence' => ['nullable', 'numeric', 'min:0', 'max:10'],
        ]);

        $channel->update([
            'risk_percentage' => $this->blank('risk_percentage') ? null : (float) $this->form['risk_percentage'],
            'copier_levels' => $this->blank('copier_levels') ? null : $this->form['copier_levels'],
            'max_trades_per_day' => $this->blank('max_trades_per_day') ? null : (int) $this->form['max_trades_per_day'],
            'min_confluence' => $this->blank('min_confluence') ? null : (float) $this->form['min_confluence'],
            'symbols_allow' => $this->symbols('symbols_allow'),
            'symbols_deny' => $this->symbols('symbols_deny'),
            'read_images' => $this->blank('read_images') ? null : $this->form['read_images'] === '1',
        ]);

        $this->editing = null;

        $this->dispatch('notify', message: "{$channel->label()} updated.", type: 'success');
    }

    private function blank(string $field): bool
    {
        return trim((string) ($this->form[$field] ?? '')) === '';
    }

    /**
     * @return array<int, string>|null
     */
    private function symbols(string $field): ?array
    {
        $raw = array_filter(array_map(
            fn (string $s) => strtoupper(trim($s)),
            explode(',', (string) ($this->form[$field] ?? '')),
        ));

        return $raw === [] ? null : array_values($raw);
    }

    public function expand(?int $channelId): void
    {
        $this->expanded = $this->expanded === $channelId ? null : $channelId;
    }

    /**
     * Ask for a private chat to be watched, by name.
     *
     * `announce()` reports channels, groups and bots and deliberately not people, so a
     * provider who delivers by direct message has no way of appearing in the list. This is
     * how one gets named - by its owner, one at a time, rather than by inventorying every
     * conversation they have.
     *
     * The row is created unresolved and unenabled. The dashboard cannot turn a username
     * into a chat id; only a client that is signed in can, so this records the request and
     * the collector answers it.
     */
    public function watchPrivate(): void
    {
        $this->validate(['privateUsername' => ['required', 'string', 'regex:/^@?[A-Za-z0-9_]{4,32}$/']]);

        $username = ltrim(trim($this->privateUsername), '@');

        if (TelegramChannel::where('user_id', Auth::id())->where('username', $username)->exists()) {
            $this->privateUsername = '';
            $this->dispatch('notify', type: 'info', message: "@{$username} is already on the list.");

            return;
        }

        TelegramChannel::create([
            'user_id' => Auth::id(),
            'source' => TelegramChannel::SOURCE_ACCOUNT,
            'kind' => TelegramChannel::KIND_USER,
            // A placeholder until a signed-in client says otherwise. Unique per user, and
            // no incoming message can ever match it - so a pending row cannot be traded
            // even if somebody managed to enable it.
            'chat_id' => "pending:{$username}",
            'username' => $username,
            'title' => "@{$username}",
            'is_enabled' => false,
            'resolve_state' => TelegramChannel::RESOLVE_PENDING,
        ]);

        $this->privateUsername = '';

        $this->dispatch('notify', type: 'success', message: "Looking up @{$username}. It appears once the collector confirms the account exists.");
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
            // What a blank field would inherit, shown as placeholder text.
            'defaults' => BotSettings::where('user_id', Auth::id())->first(),
        ]);
    }
}
