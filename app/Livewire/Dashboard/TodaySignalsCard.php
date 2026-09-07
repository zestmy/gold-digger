<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Pages\Signals;
use App\Models\BotHeartbeat;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\TelegramSignal;
use App\Models\User;
use App\Services\Strategy\SignalCard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Today's Signals Card
 *
 * Everything that fired today, from both sources, in one list: the system's own AI
 * signals and the ones copied from Telegram providers. Newest first, and only today -
 * this is the glance on the way in, not the archive. The Signals page is the archive.
 *
 * ## Two tables, one list
 *
 * AI signals live in `signals`, copied ones in `telegram_signals`, and they carry their
 * levels differently: a stored signal has three target columns and its zone in `features`,
 * a copied one has a target list and its zone as a second entry column. Each row here is
 * flattened to the same shape before the view sees it, so the view renders a signal and
 * never asks where it came from except to label it.
 *
 * ## The chip says what to do, or what was done
 *
 * For an AI signal the chip is SignalCard's guidance against the last stored close - the
 * same reading the Signals page gives, so the two never disagree - unless the strategy
 * already acted on it, in which case what happened outranks what a reader might do:
 * TRADED, or HELD with the reason it was held. For a copied signal the chip is where it
 * got to in the copier's pipeline, execution first because that is the later stage.
 *
 * Nothing here is computed that the row does not already carry or arithmetic on it cannot
 * reproduce; see SignalCard for why that matters.
 */
class TodaySignalsCard extends Component
{
    /** The card is a glance, not a feed. Anything older than the eighth row is on the Signals page. */
    public const LIMIT = 8;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public int $aiCount = 0;

    public int $copiedCount = 0;

    public function mount(): void
    {
        $this->refreshSignals();
    }

    /**
     * Re-read today's rows. Called by wire:poll from the view.
     */
    public function refreshSignals(): void
    {
        /** @var User $user */
        $user = Auth::user();

        // "Today" is the reader's day, not the server's. The boundary is converted back to
        // UTC before it reaches the query because that is what the columns hold.
        $start = Carbon::now($user->zone())->startOfDay()->utc();

        $heartbeat = BotHeartbeat::where('user_id', $user->id)
            ->orderByDesc('last_seen_at')
            ->first();

        $strategyIds = Strategy::where('user_id', $user->id)->pluck('id');

        $ai = Signal::whereIn('strategy_id', $strategyIds)
            ->where('generated_at', '>=', $start)
            ->orderByDesc('generated_at')
            ->get();

        // Follow-ups and layers are instructions about a position, not signals; and an
        // autonomous row is the AI's own trade, already counted through the fund. The
        // list is what a provider actually posted, dated by when they posted it - the
        // capture time only where the message came without one.
        $copied = TelegramSignal::with('channel')
            ->where('user_id', $user->id)
            ->where('kind', TelegramSignal::KIND_SIGNAL)
            ->where(fn ($q) => $q
                ->where('posted_at', '>=', $start)
                ->orWhere(fn ($q) => $q->whereNull('posted_at')->where('created_at', '>=', $start)))
            ->get();

        $this->aiCount = $ai->count();
        $this->copiedCount = $copied->count();

        $card = app(SignalCard::class);

        $rows = $ai->map(fn (Signal $signal) => $this->aiRow($signal, $card, $heartbeat))
            ->concat($copied->map(fn (TelegramSignal $signal) => $this->copiedRow($signal)))
            ->sortByDesc('at')
            ->take(self::LIMIT)
            ->values();

        $this->rows = $rows->map(function (array $row) {
            $row['at'] = $row['at']->toIso8601String();

            return $row;
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function aiRow(Signal $signal, SignalCard $card, ?BotHeartbeat $heartbeat): array
    {
        // The last stored close for this instrument, the same price the Signals page reads
        // the card against. A live tick is something only the terminal has.
        $bar = $heartbeat?->broker_account_id === null
            ? null
            : Candle::query()
                ->series($heartbeat->broker_account_id, $signal->symbol, $signal->timeframe)
                ->orderByDesc('open_time')
                ->first();

        $reading = $card->for($signal, $bar === null ? null : (float) $bar->close, $bar?->open_time);

        [$chip, $tone] = $this->aiChip($signal, $reading);

        return [
            'source' => 'ai',
            'source_label' => 'AI',
            'symbol' => $signal->symbol,
            'timeframe' => $signal->timeframe,
            'direction' => $signal->direction,
            'at' => $signal->generated_at ?? $signal->created_at,
            'zone_low' => $reading['zone_low'],
            'zone_high' => $reading['zone_high'],
            'stop' => $reading['stop'],
            'targets' => array_column($reading['targets'], 'price'),
            'reward_ratio' => $reading['reward_ratio'],
            'confidence' => $reading['confidence'],
            'grade' => $reading['grade'],
            'chip' => $chip,
            'tone' => $tone,
            // Straight onto the card for this row: the Signals page reads the selected
            // id from its address, so a row here opens the same signal there.
            'href' => route('signals', ['selected' => $signal->id]),
        ];
    }

    /**
     * What happened outranks what a reader might do. A signal the strategy already turned
     * into a position, or refused, is reported as that; only one it neither took nor
     * refused is read for entry.
     *
     * @param  array<string, mixed>  $reading
     * @return array{0: string, 1: string}
     */
    private function aiChip(Signal $signal, array $reading): array
    {
        if ($signal->was_executed) {
            return ['TRADED', 'go'];
        }

        if ($signal->skip_reason !== null) {
            $label = Signals::REASONS[$signal->skip_reason]['label'] ?? str_replace('_', ' ', $signal->skip_reason);

            return ['HELD: '.$label, 'muted'];
        }

        return [$reading['guidance']['headline'], $reading['guidance']['tone']];
    }

    /**
     * @return array<string, mixed>
     */
    private function copiedRow(TelegramSignal $signal): array
    {
        $entry = $signal->entry_price;
        $zoneHigh = $signal->entry_zone_high ?? $entry;
        $targets = array_values(array_map('floatval', array_filter((array) ($signal->tp_prices ?? []), 'is_numeric')));

        [$chip, $tone] = $this->copiedChip($signal);

        return [
            'source' => 'copied',
            // The channel's own title where it is known; the chat title the message came
            // with otherwise. Never blank - a copied signal always came from somewhere.
            'source_label' => $signal->channel?->title ?? $signal->chat_title ?? 'Telegram',
            'symbol' => $signal->symbol ?? '—',
            'timeframe' => null,
            'direction' => $signal->direction,
            'at' => $signal->posted_at ?? $signal->created_at,
            'zone_low' => $entry === null ? null : min($entry, $zoneHigh),
            'zone_high' => $entry === null ? null : max($entry, $zoneHigh),
            'stop' => $signal->sl_price,
            'targets' => $targets,
            'reward_ratio' => $this->rewardRatio($entry, $signal->sl_price, $targets),
            'confidence' => null,
            'grade' => null,
            'chip' => $chip,
            'tone' => $tone,
            'href' => route('signals.copier'),
        ];
    }

    /**
     * Where the copied signal got to. Execution outranks review because it is the later
     * stage: an executed signal was necessarily approved, and saying so twice says less.
     *
     * @return array{0: string, 1: string}
     */
    private function copiedChip(TelegramSignal $signal): array
    {
        if ($signal->parse_status === TelegramSignal::PARSE_FAILED) {
            return ['NOT PARSED', 'muted'];
        }

        if ($signal->execution_status !== TelegramSignal::EXEC_NONE) {
            return match ($signal->execution_status) {
                TelegramSignal::EXEC_EXECUTED => ['EXECUTED', 'go'],
                TelegramSignal::EXEC_QUEUED => ['QUEUED', 'wait'],
                TelegramSignal::EXEC_BLOCKED => ['BLOCKED', 'stop'],
                default => ['FAILED', 'stop'],
            };
        }

        return match ($signal->review_status) {
            TelegramSignal::REVIEW_APPROVED => ['APPROVED', 'go'],
            TelegramSignal::REVIEW_DECLINED => ['DECLINED', 'muted'],
            TelegramSignal::REVIEW_SKIPPED => ['NOT TRADED', 'muted'],
            default => ['AWAITING REVIEW', 'wait'],
        };
    }

    /**
     * Reward against risk on the final rung, the same rule SignalCard applies to its own
     * rows, so an AI signal and a copied one at the same levels read the same ratio.
     *
     * @param  array<int, float>  $targets
     */
    private function rewardRatio(?float $entry, ?float $stop, array $targets): ?float
    {
        if ($entry === null || $stop === null || $targets === []) {
            return null;
        }

        $risk = abs($entry - $stop);

        if ($risk <= 0.0) {
            return null;
        }

        return round(abs(end($targets) - $entry) / $risk, 2);
    }

    public function render()
    {
        return view('livewire.dashboard.today-signals-card');
    }
}
