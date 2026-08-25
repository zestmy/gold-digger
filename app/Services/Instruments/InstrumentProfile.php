<?php

namespace App\Services\Instruments;

/**
 * Instrument Profile
 *
 * What kind of thing a symbol is, and the handful of behaviours that follow from it.
 *
 * ## Why this exists
 *
 * Everything in this system was written for one instrument, and several places quietly
 * assume gold's shape:
 *
 * - `NewsBlackout::currenciesFor()` reads a currency pair off a six-letter name. `US30`
 *   has no second half, so an index would be exposed to no releases at all - and the one
 *   thing an index reacts to violently is exactly the US calendar.
 * - The feed-stall check suppresses weekend alerts because FX is shut. Crypto is not, and
 *   a silent weekend outage on BTCUSD would be invisible.
 * - Session gating assumes London and New York matter. For crypto they do not.
 *
 * None of that is wrong for gold. All of it is wrong for something else, and the failure
 * mode in each case is silence rather than an error.
 *
 * ## Classification is by rule, with overrides
 *
 * Brokers name instruments inconsistently - `US30`, `US30Cash`, `DJ30`, `#USNDAQ100` - so
 * the rules handle the common shapes and `config/instruments.php` handles the rest. An
 * unrecognised symbol is reported as `unknown` rather than guessed at: a wrong guess here
 * means trading an index through gold's assumptions.
 */
final class InstrumentProfile
{
    public const FX = 'fx';

    public const METAL = 'metal';

    public const INDEX = 'index';

    public const ENERGY = 'energy';

    public const CRYPTO = 'crypto';

    public const UNKNOWN = 'unknown';

    /** ISO codes that can appear as a leg of an FX pair. */
    private const CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'NZD', 'CAD', 'CHF',
        'SEK', 'NOK', 'DKK', 'PLN', 'HUF', 'CZK', 'TRY', 'ZAR',
        'MXN', 'SGD', 'HKD', 'CNH', 'THB', 'ILS',
    ];

    /** Metals quoted as a pair: XAUUSD, XAGEUR. */
    private const METALS = ['XAU', 'XAG', 'XPT', 'XPD'];

    /** Crypto bases. Not exhaustive, and does not need to be - config covers the rest. */
    private const CRYPTO_BASES = ['BTC', 'ETH', 'LTC', 'XRP', 'BCH', 'ADA', 'SOL', 'DOT', 'DOGE'];

    /**
     * Everything about a symbol that the rest of the system needs to behave correctly.
     *
     * @return array{
     *     symbol: string,
     *     base: string,
     *     kind: string,
     *     currencies: array<int, string>,
     *     trades_weekend: bool,
     *     session_gated: bool,
     * }
     */
    public function for(string $symbol): array
    {
        $clean = $this->normalise($symbol);

        // Explicit configuration always wins. Broker naming is too varied for rules alone,
        // and an operator who has told us what something is should not be second-guessed.
        $override = config('instruments.overrides.'.$clean);

        if (is_array($override)) {
            return $this->build($symbol, $clean, $override['kind'], $override['currencies'] ?? []);
        }

        [$kind, $currencies] = $this->classify($clean);

        return $this->build($symbol, $clean, $kind, $currencies);
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function classify(string $clean): array
    {
        // Indices first: their names are the least regular and would otherwise be mangled
        // by the six-letter pair rule (GER40 is not a currency pair).
        foreach ((array) config('instruments.indices', []) as $pattern => $currency) {
            if (str_starts_with($clean, strtoupper($pattern))) {
                return [self::INDEX, [$currency]];
            }
        }

        foreach ((array) config('instruments.energy', []) as $pattern => $currency) {
            if (str_starts_with($clean, strtoupper($pattern))) {
                return [self::ENERGY, [$currency]];
            }
        }

        $head = substr($clean, 0, 3);
        $tail = substr($clean, 3, 3);

        if (in_array($head, self::CRYPTO_BASES, true)) {
            // The quote leg still matters - a USD release moves BTCUSD through the dollar -
            // but only if the quote is a currency we know.
            return [self::CRYPTO, in_array($tail, self::CURRENCIES, true) ? [$tail] : []];
        }

        if (in_array($head, self::METALS, true)) {
            // The metal itself has no economic calendar; the quote currency does.
            return [self::METAL, in_array($tail, self::CURRENCIES, true) ? [$tail] : []];
        }

        if (in_array($head, self::CURRENCIES, true) && in_array($tail, self::CURRENCIES, true)) {
            return [self::FX, array_values(array_unique([$head, $tail]))];
        }

        // Deliberately not a guess. Trading an unrecognised instrument through gold's
        // assumptions is worse than refusing to classify it.
        return [self::UNKNOWN, []];
    }

    /**
     * @param  array<int, string>  $currencies
     * @return array<string, mixed>
     */
    private function build(string $symbol, string $clean, string $kind, array $currencies): array
    {
        return [
            'symbol' => $symbol,
            'base' => $clean,
            'kind' => $kind,
            'currencies' => array_values(array_unique(array_map('strtoupper', $currencies))),
            // Crypto is the only one that does not stop, which matters for the feed-stall
            // alert: a quiet Saturday is normal for gold and a fault for BTCUSD.
            'trades_weekend' => $kind === self::CRYPTO,
            // The London/New York windows describe where FX and metals liquidity is. For
            // crypto they describe nothing, and gating on them would halve the day for no
            // reason anybody could point at.
            'session_gated' => $kind !== self::CRYPTO,
        ];
    }

    /**
     * Strip broker decoration: suffixes, separators, and the cash/spot wording brokers add.
     */
    private function normalise(string $symbol): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $symbol) ?? '');

        foreach (['CASH', 'SPOT', 'ROLL', 'FT'] as $noise) {
            if (str_ends_with($clean, $noise) && strlen($clean) > strlen($noise) + 2) {
                $clean = substr($clean, 0, -strlen($noise));
            }
        }

        // A trailing lowercase suffix (XAUUSDm) is already gone with the case fold; a
        // trailing single letter on an otherwise six-letter pair is broker decoration.
        if (strlen($clean) === 7 && ctype_alpha($clean)) {
            $head = substr($clean, 0, 6);

            if (in_array(substr($head, 0, 3), array_merge(self::CURRENCIES, self::METALS, self::CRYPTO_BASES), true)) {
                $clean = $head;
            }
        }

        return $clean;
    }
}
