<?php

namespace App\Services\Backtest;

use App\Models\BotHeartbeat;
use App\Models\TradeCommand;
use App\Services\Trading\VolumeRules;

/**
 * Market Assumptions
 *
 * Every cost and friction the simulation applies, in one place, because these are the numbers
 * that decide whether a backtest is useful or flattering.
 *
 * A backtest with no spread, no slippage and fills exactly at the target will show a profit for
 * almost any strategy. The defaults here are deliberately pessimistic: the point of running one
 * is to find out whether an edge survives contact with costs, and a result that looks worse
 * than reality is recoverable in a way that the opposite is not.
 *
 * ## Candle prices are treated as bid
 *
 * Which is what MT5 charts show. A buy therefore enters at bid + spread and exits at bid; a
 * sell enters at bid and exits at bid + spread. The spread is paid once per round trip, on the
 * side that actually crosses it, rather than being halved at both ends - the same total, but it
 * lands where it really lands.
 */
final readonly class MarketAssumptions
{
    /**
     * @param  float  $pipSize  Price movement of one pip. Gold: 0.10
     * @param  float  $pipValuePerLot  Account-currency value of a one-pip move on one lot
     * @param  float  $pointSize  Broker point, used to read spread_points off a candle
     * @param  float|null  $spreadPips  Fixed spread, or null to read each bar's own
     * @param  float  $slippagePips  Adverse slippage on every market order
     * @param  float  $commissionPerLot  Charged per lot per side
     * @param  float  $startingBalance  What the account starts with
     * @param  float  $volumeStep  Broker's lot granularity; sizes snap down onto it
     * @param  float  $volumeMin  Smallest lot the broker accepts; below it the setup is declined
     * @param  float  $latencySeconds  Bar close to order reaching the broker. See latencyFraction()
     */
    public function __construct(
        public float $pipSize = 0.10,
        public float $pipValuePerLot = 10.0,
        public float $pointSize = 0.01,
        public ?float $spreadPips = null,
        public float $slippagePips = 0.3,
        public float $commissionPerLot = 7.0,
        public float $startingBalance = 10000.0,
        public float $volumeStep = VolumeRules::DEFAULT_STEP,
        public float $volumeMin = VolumeRules::DEFAULT_MIN,
        public float $latencySeconds = 0.0,
    ) {}

    /**
     * Bar close to the order reaching the broker, when nothing has been measured.
     *
     * Two poll intervals at the EA's default `PollSeconds = 5`: the bar closes and waits up
     * to one interval to be pushed, the command is queued and waits up to another to be
     * claimed. Ten seconds is the far end of that rather than the middle, because this is
     * the figure used when there is nothing to measure, and the house rule where the data is
     * silent is to take the reading that can say no.
     *
     * A deployment with commands in the queue does not use this - see measuredLatency().
     */
    public const DEFAULT_LATENCY_SECONDS = 10.0;

    /**
     * The unmeasured leg: bar close to the EA pushing that bar.
     *
     * `trade_commands` timestamps start when the dashboard queues a command, which is after
     * the bar arrived. That first hop leaves no timestamp of its own to subtract, so a
     * measured latency is the queue wait plus one nominal poll interval for the leg nobody
     * recorded. Naming it here is the point: it is an assumption sitting inside a
     * measurement, and it should be visible as one.
     */
    public const PUSH_SECONDS = 5.0;

    /**
     * Build from what the terminal has actually reported, falling back to gold defaults.
     *
     * Using the live symbol specification matters: a backtest run with a pip value the broker
     * does not use is measuring a different instrument, and position sizing is a division by
     * exactly that number.
     */
    public static function fromHeartbeat(?BotHeartbeat $heartbeat, array $overrides = []): self
    {
        $pipSize = $overrides['pipSize']
            ?? ($heartbeat?->pip_size !== null ? (float) $heartbeat->pip_size : 0.10);

        return new self(
            pipSize: $pipSize,
            pipValuePerLot: $overrides['pipValuePerLot']
                ?? ($heartbeat?->pip_value_per_lot !== null ? (float) $heartbeat->pip_value_per_lot : 10.0),
            // Conventionally a pip is ten points - true for gold quoted to 2 digits and for
            // a 5-digit FX pair alike. Overridable because "conventionally" is not "always".
            pointSize: $overrides['pointSize'] ?? ($pipSize / 10),
            spreadPips: $overrides['spreadPips'] ?? null,
            slippagePips: $overrides['slippagePips'] ?? 0.3,
            commissionPerLot: $overrides['commissionPerLot'] ?? 7.0,
            startingBalance: $overrides['startingBalance'] ?? 10000.0,
            // The grid the executor will snap to. Simulating an unsnapped size measures a
            // position the broker would never have held - and where the honest size falls
            // below the minimum, the terminal raises it rather than refusing, which is
            // more risk than the setting asked for. See VolumeRules.
            volumeStep: $overrides['volumeStep']
                ?? ($heartbeat?->volume_step !== null ? (float) $heartbeat->volume_step : VolumeRules::DEFAULT_STEP),
            volumeMin: $overrides['volumeMin']
                ?? ($heartbeat?->volume_min !== null ? (float) $heartbeat->volume_min : VolumeRules::DEFAULT_MIN),
            latencySeconds: $overrides['latencySeconds']
                ?? self::measuredLatency($heartbeat)
                ?? self::DEFAULT_LATENCY_SECONDS,
        );
    }

    /**
     * How long this account's orders really wait, from the queue's own record.
     *
     * `trade_commands` stores `created_at` and `claimed_at`, so the wait between the
     * dashboard deciding and an executor picking the command up is not a guess - it is a
     * measurement, per account, in the deployment's own conditions. The median is taken
     * rather than the mean: one command queued while the terminal was closed for the
     * weekend would otherwise set the assumption for every trade.
     *
     * Returns null when there is not enough of it to be worth believing, and the caller
     * falls back to DEFAULT_LATENCY_SECONDS.
     */
    public static function measuredLatency(?BotHeartbeat $heartbeat, int $sample = 200): ?float
    {
        if ($heartbeat?->broker_account_id === null) {
            return null;
        }

        $waits = TradeCommand::query()
            ->where('broker_account_id', $heartbeat->broker_account_id)
            ->whereNotNull('claimed_at')
            // A row written without timestamps has nothing to subtract from.
            ->whereNotNull('created_at')
            ->orderByDesc('id')
            ->limit($sample)
            ->get(['created_at', 'claimed_at'])
            ->map(fn (TradeCommand $c) => (float) ($c->claimed_at->getTimestamp() - $c->created_at->getTimestamp()))
            // A negative wait is a clock disagreeing with itself, not a fast broker.
            ->filter(fn (float $seconds) => $seconds >= 0.0)
            ->sort()
            ->values();

        // Fewer than this and the median is one bad afternoon rather than a distribution.
        if ($waits->count() < 10) {
            return null;
        }

        $middle = (int) floor($waits->count() / 2);

        $median = $waits->count() % 2 === 1
            ? $waits[$middle]
            : ($waits[$middle - 1] + $waits[$middle]) / 2;

        return $median + self::PUSH_SECONDS;
    }

    /**
     * How much of one bar the latency covers, as a fraction between 0 and 1.
     *
     * This is what turns a number of seconds into a price: the simulation cannot know where
     * inside a bar the price was after ten seconds, so it takes that share of the distance
     * the bar travelled *against* the trade. Zero latency changes nothing; a latency longer
     * than the bar itself cannot cost more than the whole adverse excursion.
     */
    public function latencyFraction(int $barSeconds): float
    {
        if ($this->latencySeconds <= 0.0 || $barSeconds <= 0) {
            return 0.0;
        }

        return min(1.0, $this->latencySeconds / $barSeconds);
    }

    /**
     * Spread for a bar, in pips.
     *
     * Prefers the bar's own recorded spread - which is why `candles.spread_points` is stored -
     * and falls back to the configured figure. A fixed spread across a backtest hides the fact
     * that spreads widen exactly when a strategy is most likely to be triggering.
     */
    public function spreadPipsFor(?float $spreadPoints): float
    {
        if ($this->spreadPips !== null) {
            return $this->spreadPips;
        }

        if ($spreadPoints === null || $spreadPoints <= 0 || $this->pipSize <= 0) {
            // No recorded spread and none configured. Two pips on gold is a normal quiet
            // market; assuming zero would be the flattering choice.
            return 2.0;
        }

        return ($spreadPoints * $this->pointSize) / $this->pipSize;
    }

    public function pipsToPrice(float $pips): float
    {
        return $pips * $this->pipSize;
    }

    public function priceToPips(float $price): float
    {
        return $this->pipSize > 0 ? $price / $this->pipSize : 0.0;
    }

    /**
     * Money value of a pip move on a given volume.
     */
    public function money(float $pips, float $lots): float
    {
        return $pips * $this->pipValuePerLot * $lots;
    }
}
