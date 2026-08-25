<?php

namespace App\Services\Telegram;

use App\Models\SymbolSpec;
use App\Models\TelegramSignal;
use App\Models\Trade;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What each signal channel is actually worth.
 *
 * ## Why the funnel is reported next to the money
 *
 * A channel's P&L on its own is close to meaningless for a copier, because the copier is
 * not obliged to take every signal. Two channels can show the same net figure while one
 * posted forty signals and traded six, and the other posted seven and traded all of them.
 * Those are completely different things to own, and only the funnel distinguishes them.
 *
 * So each row carries the whole path - posted, parsed, reviewed, executed, closed - and
 * the money sits at the end of it. A channel with excellent results on two trades is
 * visibly a channel with two trades.
 *
 * ## Unparsed messages are counted, not hidden
 *
 * A provider changing their format looks exactly like a quiet week: messages keep
 * arriving, nothing trades. The parse rate is what makes those two distinguishable, so it
 * is a first-class column rather than a diagnostic buried somewhere.
 *
 * ## R is measured against the stop we actually used
 *
 * Not the one the provider posted. When the copier substitutes its own levels the
 * provider's R:R claim describes a trade nobody took, and grading against it would credit
 * or blame them for our decision. `gross_pnl_pips x pip_size / |entry - sl|` uses the
 * position as it existed.
 *
 * Trades with no stop, or no pip size for their symbol, are excluded from R and still
 * counted in the money. A missing denominator is not a zero.
 */
final class ChannelPerformance
{
    /**
     * One row per channel, plus one for messages from chats never registered.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forUser(int $userId, ?Carbon $since = null): Collection
    {
        $signals = TelegramSignal::with(['channel', 'trade'])
            ->where('user_id', $userId)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->get();

        $pips = $this->pipSizes();

        return $signals
            ->groupBy(fn (TelegramSignal $s) => $s->telegram_channel_id ?? 0)
            ->map(fn (Collection $group) => $this->summarise($group, $pips))
            ->sortByDesc(fn (array $row) => $row['net_money'])
            ->values();
    }

    /**
     * Why this channel's signals get turned down, most common first.
     *
     * The single most useful thing on the page when a channel looks quiet: "declined 80%"
     * is a number, "declined 80%, and 14 of those were 'stop already passed'" is an
     * instruction - that channel posts late, and nothing about the strategy will fix it.
     *
     * @return array<string, int>
     */
    public function declineReasons(int $userId, ?int $channelId = null, int $limit = 6): array
    {
        return TelegramSignal::where('user_id', $userId)
            ->when($channelId, fn ($q) => $q->where('telegram_channel_id', $channelId))
            ->where(function ($q) {
                $q->where('review_status', TelegramSignal::REVIEW_DECLINED)
                    ->orWhereIn('execution_status', [TelegramSignal::EXEC_BLOCKED, TelegramSignal::EXEC_FAILED])
                    ->orWhere('parse_status', TelegramSignal::PARSE_FAILED);
            })
            ->get()
            ->map(fn (TelegramSignal $s) => $this->reasonFor($s))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take($limit)
            ->all();
    }

    /**
     * @param  Collection<int, TelegramSignal>  $signals
     * @param  array<string, float>  $pips
     * @return array<string, mixed>
     */
    private function summarise(Collection $signals, array $pips): array
    {
        $first = $signals->first();

        $parsed = $signals->where('parse_status', TelegramSignal::PARSE_OK);
        $declined = $signals->where('review_status', TelegramSignal::REVIEW_DECLINED);
        $approved = $signals->where('review_status', TelegramSignal::REVIEW_APPROVED);
        $executed = $signals->where('execution_status', TelegramSignal::EXEC_EXECUTED);

        $trades = $signals->pluck('trade')->filter();

        // The model owns which statuses are still live; asking it means a new status
        // cannot start silently counting as a finished trade.
        $closed = $trades->reject(fn (Trade $t) => $t->isOpen());

        $wins = $closed->filter(fn (Trade $t) => (float) $t->net_pnl_money > 0);
        $losses = $closed->filter(fn (Trade $t) => (float) $t->net_pnl_money < 0);

        $won = (float) $wins->sum('net_pnl_money');
        // Absolute, so profit factor reads the conventional way round.
        $lost = abs((float) $losses->sum('net_pnl_money'));

        $rs = $closed
            ->map(fn (Trade $t) => $this->rMultiple($t, $pips))
            ->filter(fn (?float $r) => $r !== null)
            ->values();

        return [
            'channel' => $first?->channel,
            // Chats that were never registered - historic rows, or a source removed since.
            'label' => $first?->channel?->label() ?? ($first?->chat_title ?: 'Unregistered'),
            'enabled' => (bool) $first?->channel?->is_enabled,

            // ---- funnel ----
            'messages' => $signals->count(),
            'parsed' => $parsed->count(),
            'parse_rate' => $this->rate($parsed->count(), $signals->count()),
            'approved' => $approved->count(),
            'declined' => $declined->count(),
            'decline_rate' => $this->rate($declined->count(), $approved->count() + $declined->count()),
            'executed' => $executed->count(),

            // ---- results ----
            'open' => $trades->filter(fn (Trade $t) => $t->isOpen())->count(),
            'closed' => $closed->count(),
            'wins' => $wins->count(),
            'losses' => $losses->count(),
            'win_rate' => $this->rate($wins->count(), $closed->count()),
            'net_money' => round($won - $lost, 2),
            'won_money' => round($won, 2),
            'lost_money' => round($lost, 2),
            // A channel that has never lost has no profit factor, rather than an infinite
            // one. Printing a division by zero as a headline number would be a lie about
            // how much is known.
            'profit_factor' => $lost > 0 ? round($won / $lost, 2) : null,
            'expectancy' => $closed->count() > 0 ? round(($won - $lost) / $closed->count(), 2) : null,
            'avg_r' => $rs->isNotEmpty() ? round($rs->avg(), 2) : null,
            'best_r' => $rs->isNotEmpty() ? round($rs->max(), 2) : null,
            'worst_r' => $rs->isNotEmpty() ? round($rs->min(), 2) : null,
            'graded' => $rs->count(),
        ];
    }

    /**
     * Realised result as a multiple of what was risked.
     *
     * Null when the denominator is unknown, which is the honest answer: a trade with no
     * recorded stop cannot be graded, and treating it as 0R would drag the average toward
     * a number nobody measured.
     *
     * @param  array<string, float>  $pips
     */
    private function rMultiple(Trade $trade, array $pips): ?float
    {
        $pip = $pips[$trade->symbol] ?? null;
        $risk = abs((float) $trade->entry_price - (float) $trade->sl_price);

        if ($pip === null || $pip <= 0.0 || $risk <= 0.0 || $trade->sl_price === null) {
            return null;
        }

        return ((float) $trade->gross_pnl_pips * $pip) / $risk;
    }

    /**
     * The one sentence that explains why this signal did not become a position.
     */
    private function reasonFor(TelegramSignal $signal): ?string
    {
        if ($signal->parse_status === TelegramSignal::PARSE_FAILED) {
            return $signal->parse_error ?: 'Could not be parsed';
        }

        if (in_array($signal->execution_status, [TelegramSignal::EXEC_BLOCKED, TelegramSignal::EXEC_FAILED], true)) {
            return $signal->execution_note ?: 'Execution refused';
        }

        // Review reasoning is a paragraph from a model. Grouping on the whole thing gives
        // a list where every entry has a count of one, which answers nothing - the first
        // sentence is the reason and the rest is its argument.
        $reasoning = trim((string) $signal->review_reasoning);

        if ($reasoning === '') {
            return 'Declined';
        }

        $sentence = preg_split('/(?<=[.!?])\s+/', $reasoning)[0] ?? $reasoning;

        return mb_substr($sentence, 0, 120);
    }

    /**
     * @return array<string, float>
     */
    private function pipSizes(): array
    {
        return SymbolSpec::query()
            ->whereNotNull('pip_size')
            ->pluck('pip_size', 'symbol')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
