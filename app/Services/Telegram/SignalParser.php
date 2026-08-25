<?php

namespace App\Services\Telegram;

use App\Services\Instruments\InstrumentProfile;

/**
 * Signal Parser
 *
 * Turns a Telegram message into fields, or refuses to.
 *
 * ## It refuses more than it guesses
 *
 * Signal providers write in whatever format they like, with emoji, inconsistent
 * capitalisation, entry zones, and sometimes no stop at all. The temptation is to fill in
 * what is missing from what is usually meant.
 *
 * The one rule that matters: **a signal with no readable stop is refused.** Not defaulted,
 * not inferred from ATR, not completed from the take-profit distance. A copied trade with
 * an invented stop is the single worst thing this feature could produce - it would be a
 * position whose risk nobody chose, opened automatically, from a message nobody read.
 *
 * Direction and symbol are treated the same way. A message that says BUY and SELL, or
 * names two instruments, is ambiguous rather than "probably the first one", and the
 * ambiguity is recorded so the message can be read by a human.
 *
 * ## Unparsed is a result, not a failure
 *
 * Every message is stored either way. A provider changing their format has to be visible,
 * and it is only visible if the misses are counted.
 */
final class SignalParser
{
    /** Words that mean buy, in the shapes providers actually use. */
    private const BUY_WORDS = ['BUY', 'LONG', 'BULLISH'];

    private const SELL_WORDS = ['SELL', 'SHORT', 'BEARISH'];

    /**
     * @return array{
     *     ok: bool,
     *     error: string|null,
     *     symbol: string|null,
     *     direction: string|null,
     *     entry_price: float|null,
     *     entry_zone_high: float|null,
     *     sl_price: float|null,
     *     tp_prices: array<int, float>,
     * }
     */
    public function parse(string $message): array
    {
        $text = $this->normalise($message);

        $symbol = $this->symbol($text);

        if ($symbol === null) {
            return $this->fail('No instrument found in the message.');
        }

        $direction = $this->direction($text);

        if ($direction === null) {
            return $this->fail('No direction found, or both buy and sell appear.');
        }

        $sl = $this->firstNumberAfter($text, ['STOP LOSS', 'STOPLOSS', 'SL', 'S/L', 'STOP']);

        if ($sl === null) {
            // The rule this class exists to enforce.
            return $this->fail('No stop loss found. A signal without a readable stop is never traded.');
        }

        $tps = $this->takeProfits($text);
        [$entry, $zoneHigh] = $this->entry($text);

        // A stop on the wrong side of entry means the message was misread, not that the
        // provider meant it. Executing this would place the stop as a target.
        if ($entry !== null) {
            $stopIsBelow = $sl < $entry;

            if ($direction === 'buy' && ! $stopIsBelow) {
                return $this->fail('Parsed a buy whose stop sits above entry; the message was misread.');
            }

            if ($direction === 'sell' && $stopIsBelow) {
                return $this->fail('Parsed a sell whose stop sits below entry; the message was misread.');
            }
        }

        return [
            'ok' => true,
            'error' => null,
            'symbol' => $symbol,
            'direction' => $direction,
            'entry_price' => $entry,
            'entry_zone_high' => $zoneHigh,
            'sl_price' => $sl,
            'tp_prices' => $tps,
        ];
    }

    /**
     * Upper-case, strip emoji and decoration, normalise separators.
     */
    private function normalise(string $message): string
    {
        $text = mb_strtoupper($message);

        // Emoji and box-drawing carry no information here and break word boundaries.
        $text = preg_replace('/[^\P{C}\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', ' ', $text) ?? $text;

        // Providers separate labels from values with anything at all.
        $text = str_replace(['：', '➡', '→', '|', '•'], ' : ', $text);

        return preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    }

    private function symbol(string $text): ?string
    {
        $profile = app(InstrumentProfile::class);

        // Longest first, so US500 is not matched as US50 and XAUUSD not as XAU.
        preg_match_all('/\b[A-Z]{2,6}[0-9]{0,3}\b/', $text, $matches);
        $candidates = array_unique($matches[0] ?? []);
        usort($candidates, static fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($candidates as $candidate) {
            // GOLD is the one alias common enough to be worth handling; anything else has
            // to be a symbol the instrument classifier recognises.
            if ($candidate === 'GOLD') {
                return 'XAUUSD';
            }

            if ($profile->for($candidate)['kind'] !== InstrumentProfile::UNKNOWN) {
                return $candidate;
            }
        }

        return null;
    }

    private function direction(string $text): ?string
    {
        $buy = $this->mentions($text, self::BUY_WORDS);
        $sell = $this->mentions($text, self::SELL_WORDS);

        // Both, or neither, is ambiguous. "BUY above 2650, SELL below" is a plan, not a
        // signal, and picking one of them is inventing an instruction.
        if ($buy === $sell) {
            return null;
        }

        return $buy ? 'buy' : 'sell';
    }

    /**
     * @param  array<int, string>  $words
     */
    private function mentions(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: float|null, 1: float|null} entry, and the far side of a zone
     */
    private function entry(string $text): array
    {
        // A zone: "ENTRY 2650 - 2652". Both sides are kept; the far side is what decides
        // whether price has already run past the signal.
        if (preg_match('/\b(?:ENTRY|ENTER|BUY|SELL|@)\D{0,12}?([0-9]+(?:\.[0-9]+)?)\s*[-–]\s*([0-9]+(?:\.[0-9]+)?)/', $text, $m) === 1) {
            $a = (float) $m[1];
            $b = (float) $m[2];

            return [min($a, $b), max($a, $b)];
        }

        $entry = $this->firstNumberAfter($text, ['ENTRY PRICE', 'ENTRY', 'ENTER', 'PRICE', '@']);

        // Market orders are legitimate: "BUY GOLD NOW, SL 2645". A null entry means "at
        // market", which the executor already knows how to do.
        return [$entry, null];
    }

    /**
     * @return array<int, float>
     */
    private function takeProfits(string $text): array
    {
        $found = [];

        // Numbered rungs first: TP1, TP2, TP3.
        if (preg_match_all('/\bTP\s*([1-9])\b\D{0,8}?([0-9]+(?:\.[0-9]+)?)/', $text, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $found[(int) $match[1]] = (float) $match[2];
            }

            ksort($found);

            return array_values($found);
        }

        // Then a single label with one or more values after it: "TAKE PROFIT: 2655, 2660".
        if (preg_match('/\b(?:TAKE\s*PROFIT|TAKEPROFIT|TP|T\/P|TARGET)\b\s*:?\s*((?:[0-9]+(?:\.[0-9]+)?[,\s\/]*){1,5})/', $text, $m) === 1) {
            preg_match_all('/[0-9]+(?:\.[0-9]+)?/', $m[1], $numbers);

            return array_map('floatval', $numbers[0] ?? []);
        }

        return [];
    }

    /**
     * The first number following any of these labels.
     *
     * @param  array<int, string>  $labels
     */
    private function firstNumberAfter(string $text, array $labels): ?float
    {
        foreach ($labels as $label) {
            // Word boundaries only where the label actually has a word character to bound.
            // '\b@\b' can never match - '@' is not a word character - so anchoring it that
            // way silently dropped every "BUY @ 2650" entry, and with no entry the
            // stop-on-the-wrong-side check had nothing to compare against either.
            $left = preg_match('/^\w/', $label) === 1 ? '\b' : '';
            $right = preg_match('/\w$/', $label) === 1 ? '\b' : '';

            $pattern = '/'.$left.preg_quote($label, '/').$right.'\s*:?\s*@?\s*([0-9]+(?:\.[0-9]+)?)/';

            if (preg_match($pattern, $text, $m) === 1) {
                return (float) $m[1];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'symbol' => null,
            'direction' => null,
            'entry_price' => null,
            'entry_zone_high' => null,
            'sl_price' => null,
            'tp_prices' => [],
        ];
    }
}
