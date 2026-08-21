<?php

namespace App\Http\Controllers\Api\Bot;

use App\Http\Controllers\Controller;
use App\Models\BotToken;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\Strategy;
use App\Services\Strategy\SignalGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Candle Controller
 *
 * Where the executor pushes closed bars, and - when a bar is genuinely new - where signal
 * generation is triggered.
 *
 * ## Bar times arrive as UTC unix timestamps
 *
 * MT5's iTime() returns *server* time, which for most retail brokers is UTC+2 or UTC+3 and
 * shifts with the broker's own daylight saving. Storing that unconverted would put every
 * bar in an hour it did not happen in, and the session filter would gate London against
 * the wrong window. The EA converts with TimeGMT() before sending, so everything on this
 * side of the wire is UTC.
 *
 * ## Only closed bars
 *
 * A forming bar's high, low and close all still move. An EMA cross computed on one can
 * appear and vanish inside the same bar, which is an entry the completed bar never
 * justified. The EA sends only completed bars; `include_current` is not an option offered.
 *
 * ## Why evaluation only runs on new bars
 *
 * The EA re-sends a trailing window on every push so a dropped request self-heals. Almost
 * every push therefore contains bars already stored. Running the strategy on all of them
 * would re-evaluate the same closed bar repeatedly - harmless, because Signal creation is
 * keyed on the bar time, but it would run the indicator stack every few seconds for
 * nothing. Evaluation is gated on at least one bar being new to this series.
 *
 * ## Why this is synchronous
 *
 * WebRequest blocks the terminal's event thread, which is why fills are buffered rather
 * than posted from OnTradeTransaction. The work here is bounded differently: a few hundred
 * bars of arithmetic and a handful of indexed queries, and only on a bar close - once per
 * five minutes on the default M5, not once per fill. Queueing it would buy little and add
 * a worker this deployment does not yet run.
 */
class CandleController extends Controller
{
    /**
     * Largest window accepted in one push.
     *
     * The steady state is a handful of bars; the big push is the one an EA makes when it
     * first attaches and has no history stored. This ceiling covers that without letting a
     * malformed request ask the server to insert an unbounded batch.
     */
    private const MAX_BARS = 1000;

    public function store(Request $request, SignalGenerator $generator): JsonResponse
    {
        /** @var BotToken $token */
        $token = $request->attributes->get('bot_token');

        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32'],
            'timeframe' => ['required', 'string', 'max:10'],
            'source' => ['nullable', 'string', 'max:50'],
            'bars' => ['required', 'array', 'min:1', 'max:'.self::MAX_BARS],
            'bars.*.time' => ['required', 'integer', 'min:1'],
            'bars.*.open' => ['required', 'numeric'],
            'bars.*.high' => ['required', 'numeric'],
            'bars.*.low' => ['required', 'numeric'],
            'bars.*.close' => ['required', 'numeric'],
            'bars.*.tick_volume' => ['nullable', 'integer', 'min:0'],
            'bars.*.spread_points' => ['nullable', 'numeric'],
        ]);

        // A series must belong to an account. The unique index that stops a re-pushed bar
        // from being stored twice cannot fire on a NULL account id, so an unbound token
        // would quietly accumulate duplicate bars. FillController refuses unbound tokens
        // for its own reasons; this endpoint refuses them for this one.
        if ($token->broker_account_id === null) {
            return response()->json([
                'message' => 'This token is not bound to a broker account; candles cannot be attributed to a series.',
            ], 422);
        }

        $accountId = $token->broker_account_id;
        $symbol = $data['symbol'];
        $timeframe = strtoupper($data['timeframe']);
        $source = $data['source'] ?? 'mql5_ea';

        $rows = [];
        $times = [];

        foreach ($data['bars'] as $bar) {
            $openTime = Carbon::createFromTimestampUTC($bar['time']);
            $times[] = $openTime->toDateTimeString();

            $rows[] = [
                'user_id' => $token->user_id,
                'broker_account_id' => $accountId,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'open_time' => $openTime,
                'open' => $bar['open'],
                'high' => $bar['high'],
                'low' => $bar['low'],
                'close' => $bar['close'],
                'tick_volume' => $bar['tick_volume'] ?? 0,
                'spread_points' => $bar['spread_points'] ?? null,
                'source' => $source,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Which of these bars we had not seen. Read before the upsert, because afterwards
        // there is no way to tell an inserted row from an updated one.
        $known = Candle::query()
            ->series($accountId, $symbol, $timeframe)
            ->whereIn('open_time', $times)
            ->pluck('open_time')
            ->map(fn ($t) => $t->toDateTimeString())
            ->all();

        $newBars = array_values(array_diff(array_unique($times), $known));

        // Upsert rather than insert: a re-pushed bar must overwrite. A bar's high and low
        // are final once closed, but a corrected feed or a re-send after a partial write
        // should still converge on the terminal's version.
        Candle::upsert(
            $rows,
            ['broker_account_id', 'symbol', 'timeframe', 'open_time'],
            ['open', 'high', 'low', 'close', 'tick_volume', 'spread_points', 'source', 'updated_at'],
        );

        $signals = [];

        if ($newBars !== []) {
            $signals = $this->evaluate($generator, $token->user_id, $timeframe, $accountId);
        }

        return response()->json([
            'stored' => count($rows),
            'new_bars' => count($newBars),
            'signals' => array_map(static fn ($s) => [
                'id' => $s->id,
                'direction' => $s->direction,
                'skip_reason' => $s->skip_reason,
            ], $signals),
        ], 201);
    }

    /**
     * Run every active strategy whose *entry* timeframe is the one that just closed a bar.
     *
     * Strategies on other entry timeframes are not evaluated: their own bar has not closed,
     * so the newest bar they would read is the same one they already acted on. A trend
     * timeframe push therefore stores its bars and generates nothing, which is correct -
     * the trend series is an input to the next entry bar, not a trigger of its own.
     *
     * @return array<int, Signal>
     */
    private function evaluate(SignalGenerator $generator, int $userId, string $timeframe, ?int $accountId): array
    {
        $strategies = Strategy::where('user_id', $userId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Strategy $s) => strtoupper($s->timeframe_entry) === $timeframe);

        $signals = [];

        foreach ($strategies as $strategy) {
            $signal = $generator->generate($strategy, $accountId);

            if ($signal !== null) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }
}
