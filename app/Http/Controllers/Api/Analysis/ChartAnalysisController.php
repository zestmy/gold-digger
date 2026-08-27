<?php

namespace App\Http\Controllers\Api\Analysis;

use App\Http\Controllers\Controller;
use App\Models\BotHeartbeat;
use App\Models\BotToken;
use App\Models\Candle;
use App\Models\ChartAnalysis;
use App\Models\Strategy;
use App\Services\Ai\AiSpend;
use App\Services\Ai\ChartAnalyst;
use App\Services\Ai\OpenRouter;
use App\Services\Analysis\MarketScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chart Analysis API
 *
 * The analysis surfaces, for a client that is not this application's own Blade.
 *
 * ## It reuses the credential that already exists
 *
 * `bot.auth` and `bot_tokens`, not a second authentication system. The tokens are already
 * per-tenant, SHA-256 hashed, revocable from the page that issued them, and - since the
 * tenancy work - `AuthenticateBot` names the tenant for the rest of the request, so every
 * model in here filters itself without a single `where('user_id', ...)` below.
 *
 * Adding Sanctum for this would have meant a second way to be authenticated, a second place
 * for that to be wrong, and no capability the existing one lacks.
 *
 * ## Two endpoints, because only one of them costs money
 *
 * `quick` returns the measured half: levels found by definition, structure, the timeframe
 * ladder, the setup candidates. It is arithmetic, it costs nothing, and it works with no
 * API key, no credit and no network.
 *
 * `analyze` is that plus one question put to a model. It is metered against the tenant's
 * daily allowance like every other call site, and it is the only endpoint here that can
 * return "allowance used up".
 *
 * Keeping them apart means a client polling for structure is not quietly buying a paragraph
 * every time it refreshes.
 *
 * ## Nothing here places an order
 *
 * Deliberately, and it is the same boundary the dashboard keeps. This is the analysis stage
 * of `Market data -> AI analysis -> Signal -> Risk engine -> Execution`, and it stops at the
 * second arrow. Position sizing lives in `PositionSizer` and execution behind the copier's
 * own gates, neither of which is reachable from here.
 */
class ChartAnalysisController extends Controller
{
    /** Most bars one candles request may ask for. */
    private const MAX_BARS = 1000;

    private const TIMEFRAMES = ['M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1'];

    /**
     * What this account has bars for, and what it can ask about them.
     */
    public function symbols(Request $request): JsonResponse
    {
        $accountId = $this->accountId($request);

        return response()->json([
            'symbols' => MarketScanner::symbols($accountId, $this->defaultTimeframe($request)),
            'timeframes' => self::TIMEFRAMES,
            'broker_account_id' => $accountId,
            // Says which of the two halves is available. A client can render the measured
            // view unconditionally and hide the button that spends money.
            'ai' => [
                'available' => app(OpenRouter::class)->configured(),
                'allowance' => app(AiSpend::class)->allowance($this->tenantId($request)),
            ],
        ]);
    }

    /**
     * Raw bars, in the shape a charting library wants.
     *
     * Broker bars, always - these are the same series the strategy trades from, and a chart
     * showing one series beside an analysis computed on another is two halves of a page
     * quietly disagreeing about which market they describe.
     */
    public function candles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32'],
            'timeframe' => ['required', 'string', 'in:'.implode(',', self::TIMEFRAMES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_BARS],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $bars = Candle::query()
            ->series($this->accountId($request), $data['symbol'], strtoupper($data['timeframe']))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where('open_time', '>=', $from))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where('open_time', '<=', $to))
            ->orderByDesc('open_time')
            ->limit((int) ($data['limit'] ?? 300))
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'symbol' => $data['symbol'],
            'timeframe' => strtoupper($data['timeframe']),
            'count' => $bars->count(),
            'candles' => $bars->map(fn (Candle $c) => [
                // Seconds since the epoch, which is what Lightweight Charts wants for an
                // intraday series and what the dashboard's own chart already sends.
                'timestamp' => $c->open_time->getTimestamp(),
                'open' => (float) $c->open,
                'high' => (float) $c->high,
                'low' => (float) $c->low,
                'close' => (float) $c->close,
                'volume' => $c->tick_volume,
            ])->all(),
        ]);
    }

    /**
     * The measured half. No model call, no spend, no allowance consumed.
     */
    public function quick(Request $request, ChartAnalyst $analyst): JsonResponse
    {
        $data = $this->analysisRequest($request);
        $strategy = $this->strategy($request);

        if ($strategy === null) {
            return response()->json(['message' => 'This account has no strategy to read the chart against.'], 422);
        }

        $measured = $analyst->measure($strategy, $this->accountId($request), $data['symbol'], $data['timeframe']);

        if (! $measured['ok']) {
            return response()->json(['message' => $measured['error']], 422);
        }

        return response()->json($this->measuredPayload($measured));
    }

    /**
     * The measured half plus the model's reading.
     */
    public function analyze(Request $request, ChartAnalyst $analyst): JsonResponse
    {
        $data = $this->analysisRequest($request);
        $strategy = $this->strategy($request);

        if ($strategy === null) {
            return response()->json(['message' => 'This account has no strategy to read the chart against.'], 422);
        }

        $result = $analyst->analyse(
            $strategy,
            $this->accountId($request),
            $data['symbol'],
            $data['timeframe'],
            fresh: (bool) ($data['refresh'] ?? false),
        );

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        // The measured half is returned even when the model half failed - an exhausted
        // allowance or an unreachable provider degrades the response rather than emptying
        // it, and `error` says which happened.
        $measured = $analyst->measure($strategy, $this->accountId($request), $data['symbol'], $data['timeframe']);

        return response()->json($this->measuredPayload($measured) + [
            'error' => $result['error'],
            'reading' => $result['reading'],
            'analysis' => $this->latestFor($data['symbol'], $data['timeframe']),
        ]);
    }

    /**
     * One stored reading, including the ones that declined to propose anything.
     *
     * Route-model bound, and the tenant scope does the authorising: another account's
     * reading is not refused, it is not found - the same answer an id that never existed
     * gets, which is what keeps this from confirming that somebody else's analysis exists.
     */
    public function show(string $analysis): JsonResponse
    {
        return response()->json(['analysis' => $this->analysisPayload($this->find($analysis))]);
    }

    /**
     * Resolve a stored reading, scoped to the caller.
     *
     * Deliberately not route-model binding. `SubstituteBindings` runs as part of the api
     * group, before the route's own `bot.auth` - so a bound model would be resolved before
     * the tenant had been named, and the global scope would have nothing to filter by. The
     * lookup happens here instead, where the tenant is established.
     *
     * `findOrFail` on the scoped query means another account's reading is not refused, it
     * is not found: the same 404 an id that never existed gets, which is what stops this
     * confirming that somebody else's analysis exists.
     */
    private function find(string $id): ChartAnalysis
    {
        return ChartAnalysis::query()->findOrFail($id);
    }

    /**
     * Ask the same question again, past the cache.
     *
     * Metered like any other model call. A client that refreshes in a loop spends its
     * allowance, which is the intended shape - the limit is what makes this safe to expose.
     */
    public function refresh(Request $request, string $analysis, ChartAnalyst $analyst): JsonResponse
    {
        $analysis = $this->find($analysis);
        $strategy = $this->strategy($request);

        if ($strategy === null) {
            return response()->json(['message' => 'This account has no strategy to read the chart against.'], 422);
        }

        $result = $analyst->analyse(
            $strategy,
            $analysis->broker_account_id ?? $this->accountId($request),
            $analysis->symbol,
            $analysis->timeframe,
            fresh: true,
        );

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json([
            'error' => $result['error'],
            'reading' => $result['reading'],
            'analysis' => $this->latestFor($analysis->symbol, $analysis->timeframe),
        ]);
    }

    // =========================================================================
    // SHAPING
    // =========================================================================

    /**
     * @param  array<string, mixed>  $measured
     * @return array<string, mixed>
     */
    private function measuredPayload(array $measured): array
    {
        $market = $measured['market'] ?? [];

        return [
            'symbol' => $measured['symbol'],
            'timeframe' => $measured['timeframe'],
            'structure' => $measured['structure'],
            'levels' => $measured['levels'],
            'events' => $measured['events'],
            'timeframes' => $measured['timeframes']['timeframes'] ?? [],
            'agreement' => $measured['timeframes']['agreement'] ?? null,
            'bias' => $measured['timeframes']['bias'] ?? null,
            // Ranked candidates with their evidence. An empty list is the common answer and
            // means no pattern here meets enough of its own definition - see
            // SetupClassifier. It is not padded to look like a result.
            'setups' => $measured['setups'],
            'readings' => [
                'last_close' => $market['last_close'] ?? null,
                'atr' => $market['atr'] ?? null,
                'adx' => $market['adx'] ?? null,
                'trend' => $market['trend'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function analysisPayload(?ChartAnalysis $analysis): ?array
    {
        if ($analysis === null) {
            return null;
        }

        return [
            'id' => $analysis->id,
            'symbol' => $analysis->symbol,
            'timeframe' => $analysis->timeframe,
            'bar_open_time' => $analysis->bar_open_time?->toIso8601String(),
            'bias' => $analysis->bias,
            'plan' => $analysis->plan,
            'setup_type' => $analysis->setup_type,
            'headline' => $analysis->headline,
            'reasoning' => $analysis->reasoning,
            'invalidation' => $analysis->invalidation,
            // Null when the reading declined to propose anything, and null rather than a
            // plausible number. A `wait` with prices in it would read as a trade nobody
            // proposed.
            'entry' => $analysis->entry_price,
            'stop_loss' => $analysis->stop_price,
            'take_profit' => $analysis->target_price,
            'risk_reward' => $analysis->reward_ratio,
            'complete' => $analysis->isComplete(),
            'model' => $analysis->model,
            'prompt_version' => $analysis->prompt_version,
            'created_at' => $analysis->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestFor(string $symbol, string $timeframe): ?array
    {
        return $this->analysisPayload(
            ChartAnalysis::query()
                ->where('symbol', $symbol)
                ->where('timeframe', strtoupper($timeframe))
                ->orderByDesc('bar_open_time')
                ->first()
        );
    }

    // =========================================================================
    // REQUEST CONTEXT
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    private function analysisRequest(Request $request): array
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32'],
            'timeframe' => ['nullable', 'string', 'in:'.implode(',', self::TIMEFRAMES)],
            'refresh' => ['nullable', 'boolean'],
        ]);

        $data['timeframe'] = strtoupper($data['timeframe'] ?? $this->defaultTimeframe($request));

        return $data;
    }

    private function tenantId(Request $request): ?int
    {
        /** @var BotToken|null $token */
        $token = $request->attributes->get('bot_token');

        return $token?->user_id;
    }

    private function strategy(Request $request): ?Strategy
    {
        // No `where('user_id', ...)`: the tenant scope applied it when the token resolved.
        return Strategy::query()->orderByDesc('is_active')->orderBy('id')->first();
    }

    private function defaultTimeframe(Request $request): string
    {
        return strtoupper((string) (Strategy::query()->value('timeframe_entry') ?: 'M15'));
    }

    /**
     * The account whose series to read.
     *
     * A token bound to one account names it; otherwise the most recently seen terminal,
     * which is where every other part of this system looks.
     */
    private function accountId(Request $request): ?int
    {
        /** @var BotToken|null $token */
        $token = $request->attributes->get('bot_token');

        return $token?->broker_account_id
            ?? BotHeartbeat::query()->orderByDesc('last_seen_at')->value('broker_account_id');
    }
}
