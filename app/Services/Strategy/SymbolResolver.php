<?php

namespace App\Services\Strategy;

use App\Models\BotHeartbeat;
use App\Models\SymbolSpec;

/**
 * Symbol Resolver
 *
 * Answers two questions the strategy layer asks constantly: what does this broker call the
 * instrument, and what are its trading limits.
 *
 * ## Why this is one place
 *
 * `SignalGenerator`, `TradeManager` and the backtester each used to read pip size and pip value
 * straight off the latest heartbeat, which quietly encoded "there is exactly one symbol" in
 * three separate files. Routing it through here is what makes a second instrument a matter of
 * configuration rather than a change to all three.
 *
 * ## The heartbeat fallback is deliberate, and temporary
 *
 * A deployment that has been running before `symbol_specs` existed has its numbers on the
 * heartbeat and nowhere else. Falling back keeps it working through the upgrade rather than
 * having every signal refuse itself with `no_symbol_spec` until the executor next pushes bars.
 *
 * The fallback only applies to the heartbeat's own resolved symbol. It cannot answer for a
 * second instrument, because the heartbeat never had a second instrument's numbers - which is
 * the limitation this class exists to remove.
 */
final class SymbolResolver
{
    /**
     * Everything known about the instrument a strategy names.
     *
     * @return array{symbol: string, pip_size: float|null, pip_value_per_lot: float|null, volume_min: float|null, volume_step: float|null, source: string}
     */
    public function for(?int $brokerAccountId, string $symbol, ?BotHeartbeat $heartbeat = null): array
    {
        $spec = SymbolSpec::resolve($brokerAccountId, $symbol);

        if ($spec !== null) {
            return [
                'symbol' => $spec->symbol,
                'pip_size' => $spec->pip_size !== null ? (float) $spec->pip_size : null,
                'pip_value_per_lot' => $spec->pip_value_per_lot !== null ? (float) $spec->pip_value_per_lot : null,
                'volume_min' => $spec->volume_min !== null ? (float) $spec->volume_min : null,
                'volume_step' => $spec->volume_step !== null ? (float) $spec->volume_step : null,
                'source' => 'symbol_spec',
            ];
        }

        // Only usable when the heartbeat is describing this same instrument. Its columns are a
        // single symbol's worth, so applying them to a different one would be inventing a pip
        // value - precisely the guess the whole design refuses to make.
        $describesThisSymbol = $heartbeat !== null
            && $heartbeat->resolved_symbol !== null
            && ($heartbeat->resolved_symbol === $symbol || str_starts_with($heartbeat->resolved_symbol, $symbol));

        if ($describesThisSymbol) {
            return [
                'symbol' => $heartbeat->resolved_symbol,
                'pip_size' => $heartbeat->pip_size !== null ? (float) $heartbeat->pip_size : null,
                'pip_value_per_lot' => $heartbeat->pip_value_per_lot !== null ? (float) $heartbeat->pip_value_per_lot : null,
                'volume_min' => $heartbeat->volume_min !== null ? (float) $heartbeat->volume_min : null,
                'volume_step' => $heartbeat->volume_step !== null ? (float) $heartbeat->volume_step : null,
                'source' => 'heartbeat',
            ];
        }

        // Nothing known. The caller records the signal unexecuted rather than guessing.
        return [
            'symbol' => $symbol,
            'pip_size' => null,
            'pip_value_per_lot' => null,
            'volume_min' => null,
            'volume_step' => null,
            'source' => 'unknown',
        ];
    }
}
