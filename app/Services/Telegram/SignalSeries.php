<?php

namespace App\Services\Telegram;

use App\Models\BotHeartbeat;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\TelegramSignal;
use App\Services\Strategy\SymbolResolver;

/**
 * Signal Series
 *
 * The price history a copied signal is measured against.
 *
 * ## Why this exists
 *
 * The strategy layer has read its bars through `Candle::recentSeries()` since it was
 * written: one account, one symbol, one timeframe. The copier never did. Every price
 * question it asked - the last close, the ATR that sizes a substituted stop, the trend the
 * reviewer is shown - was asked as `Candle::where('symbol', $signal->symbol)`, and that
 * query is wrong in three separate ways at once.
 *
 * **It mixes timeframes.** An account pushing both M5 and H1 gold has twelve M5 bars for
 * every H1 bar, all with distinct open times, so the newest 300 rows are roughly 277 M5
 * bars interleaved with 23 H1 ones. Measured on this account's own stored gold: ATR came
 * out 4.38 on the mixture against 3.79 on M5 alone and 20.94 on H1 alone, and ADX 19.6
 * against 16.8 and 40.6. Every one of those numbers is real; the mixture's belongs to no
 * timeframe and describes no market. It went into a live stop distance.
 *
 * **It mixes accounts.** The candles table is shared, and two brokers quote gold
 * differently enough that indicators over the pair match neither - which is why the
 * migration made `broker_account_id` NOT NULL and why `scopeSeries` takes it. Without the
 * filter a stop is sized from a feed the broker placing the order never quoted.
 *
 * **It uses the wrong name.** A signal carries the symbol the provider typed - `XAUUSD` -
 * while the terminal resolves and stores `XAUUSDm`, `XAUUSD.a` or `GOLD`. The generic name
 * matches the broker's own series only by luck.
 *
 * ## Fail closed
 *
 * With no heartbeat and no active account there is no series, and this returns nothing
 * rather than falling back to "any gold bars in the table". Callers already handle absent
 * prices - the reviewer declines with "no stored price for this instrument, so the entry
 * cannot be checked" - and a decline is the correct answer to a question the system cannot
 * honestly answer. Reading somebody else's bars is not.
 */
final class SignalSeries
{
    /** Timeframes used when the account has no strategy to name its own. */
    public const FALLBACK_ENTRY = 'M5';

    public const FALLBACK_TREND = 'H1';

    /** @var array<string, array{account: int|null, symbol: string, resolved: bool}> */
    private array $memo = [];

    /**
     * Which account's series, and under which name.
     *
     * The heartbeat first, because that is the terminal that would place the order and so
     * the feed the fill happens against. An account configured but not yet reporting falls
     * back to the active broker account, which is what a freshly seeded install looks like
     * before the executor first connects.
     *
     * @return array{account: int|null, symbol: string, resolved: bool}
     */
    public function for(TelegramSignal $signal): array
    {
        // Keyed on what the answer actually depends on rather than on the signal, so a
        // page listing fifteen gold signals for one user resolves the account once.
        $key = $signal->user_id.'|'.$signal->symbol;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $heartbeat = BotHeartbeat::where('user_id', $signal->user_id)
            ->orderByDesc('last_seen_at')
            ->first();

        $account = $heartbeat?->broker_account_id
            ?? BrokerAccount::where('user_id', $signal->user_id)
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->value('id');

        $spec = app(SymbolResolver::class)->for($account, (string) $signal->symbol, $heartbeat);

        return $this->memo[$key] = [
            'account' => $account === null ? null : (int) $account,
            'symbol' => $spec['symbol'],
            'resolved' => $spec['source'] !== 'unknown',
        ];
    }

    /**
     * Which timeframes this account reads. The strategy's own, where it has one.
     *
     * @return array{entry: string, trend: string}
     */
    public function timeframes(?Strategy $strategy): array
    {
        return [
            'entry' => (string) ($strategy?->timeframe_entry ?: self::FALLBACK_ENTRY),
            'trend' => (string) ($strategy?->timeframe_trend ?: self::FALLBACK_TREND),
        ];
    }

    /**
     * One series, oldest-first, ready for `Indicators`.
     *
     * @return array<int, Candle>
     */
    public function bars(TelegramSignal $signal, string $timeframe, int $limit): array
    {
        $context = $this->for($signal);

        if ($context['account'] === null) {
            return [];
        }

        return Candle::recentSeries($context['account'], $context['symbol'], $timeframe, $limit);
    }

    /**
     * The freshest close this side of the bridge has.
     *
     * Deliberately not restricted to one timeframe, and the only read here that is not.
     * Picking the newest bar across an instrument's series is not an arithmetic over the
     * mixture - it is one bar - and at 11:20 the M5 bar that opened at 11:15 is a better
     * answer than the H1 bar that opened at 10:00. Indicators cannot be computed this way;
     * a single last price can.
     */
    public function lastClose(TelegramSignal $signal): ?float
    {
        $close = $this->newest($signal)?->close;

        return $close === null ? null : (float) $close;
    }

    /**
     * The spread at the newest bar's close, in broker points.
     */
    public function lastSpreadPoints(TelegramSignal $signal): ?float
    {
        $points = $this->newest($signal)?->spread_points;

        return $points === null ? null : (float) $points;
    }

    /**
     * The best price an open position has seen since it opened, from closed bars.
     *
     * Scoped the same way, so a trail is set from the feed the position is held on. Any
     * timeframe serves: every stored series describes the same price path, so the extreme
     * over a window is the same extreme whichever bars it is read from.
     */
    public function extremeSince(?int $account, string $symbol, \DateTimeInterface $since, bool $high): ?float
    {
        if ($account === null) {
            return null;
        }

        $value = Candle::query()
            ->where('broker_account_id', $account)
            ->where('symbol', $symbol)
            ->where('open_time', '>=', $since)
            ->{$high ? 'max' : 'min'}($high ? 'high' : 'low');

        return $value === null ? null : (float) $value;
    }

    /**
     * The freshest close for an account's instrument, named directly.
     *
     * The signal-shaped `lastClose()` above cannot serve callers holding a position rather
     * than a message. Same rule: the newest bar on any of the instrument's series, because
     * one bar is not an arithmetic over a mixture.
     */
    public function closeFor(?int $account, string $symbol): ?float
    {
        if ($account === null) {
            return null;
        }

        $close = Candle::query()
            ->where('broker_account_id', $account)
            ->where('symbol', $symbol)
            ->orderByDesc('open_time')
            ->orderByDesc('id')
            ->value('close');

        return $close === null ? null : (float) $close;
    }

    private function newest(TelegramSignal $signal): ?Candle
    {
        $context = $this->for($signal);

        if ($context['account'] === null) {
            return null;
        }

        return Candle::query()
            ->where('broker_account_id', $context['account'])
            ->where('symbol', $context['symbol'])
            ->orderByDesc('open_time')
            ->orderByDesc('id')
            ->first();
    }
}
