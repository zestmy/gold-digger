<?php

namespace App\Services\Analysis;

use Illuminate\Support\Carbon;

/**
 * One instrument, as the scanner found it.
 *
 * Every field here was measured. There is no field for "expected profit", and the omission
 * is the point: what can be computed from stored bars is how much agrees with a direction
 * and how far the next level is, and neither of those is a forecast. A column called
 * "opportunity: 87%" would be read as one.
 *
 * The prices come from `Structure`'s measured levels and the last stored close, never from
 * a model and never rounded to something that looks like a level. When there is no level
 * on the far side to put a stop beyond, the price is null and the row says so - a scan
 * that quietly invented a stop would be worse than one that came back short.
 */
final readonly class Opportunity
{
    /**
     * @param  'buy'|'sell'  $direction
     * @param  array<int, array{name: string, weight: float, met: bool, note: string}>  $factors
     * @param  array<int, array{price: float, kind: string, touches: int, last_index: int}>  $levels
     * @param  array{price: float, kind: string, touches: int, last_index: int}|null  $stopLevel
     * @param  array{price: float, kind: string, touches: int, last_index: int}|null  $targetLevel
     */
    public function __construct(
        public string $symbol,
        public string $kind,
        public string $direction,
        public float $confluence,
        public float $possible,
        public float $directional,
        public int $confidence,
        public string $risk,
        public string $entryStatus,
        public bool $tradeable,
        public string $why,
        public array $factors,
        public bool $aligned,
        public ?float $adx,
        public ?float $atr,
        public ?float $atrPct,
        public float $entry,
        public ?float $stop,
        public ?float $target,
        public ?float $rewardRatio,
        public ?array $stopLevel,
        public ?array $targetLevel,
        public string $structure,
        public array $levels,
        public ?Carbon $lastBarAt,
        public int $bars,
    ) {}

    /**
     * Is there a complete plan here, or only half of one?
     *
     * Both prices have to exist for the risk to be known, and an entry whose risk is
     * unknown is not a proposal. The scanner still lists it - the reader may want to see
     * that the setup is there and the structure is not - but it is not offered as a trade.
     */
    public function complete(): bool
    {
        return $this->stop !== null && $this->target !== null && $this->rewardRatio !== null;
    }

    /**
     * The ordering key.
     *
     * Lexicographic on measured quantities rather than a weighted composite. A single
     * score would need coefficients nobody measured, and it would hide which of the three
     * a row actually won on - the reader can see "four factors, 2.1 to 1" and disagree
     * with the ordering, which they cannot do with "83".
     *
     * @return array<int, float|int>
     */
    public function rank(): array
    {
        return [
            $this->tradeable ? 1 : 0,
            $this->complete() ? 1 : 0,
            $this->confluence,
            $this->rewardRatio ?? 0.0,
            $this->confidence,
        ];
    }

    /**
     * How this candidate is described to the model.
     *
     * Numbers only, and every one of them computed here. The model is choosing among
     * these, not producing them.
     */
    public function brief(int $index): string
    {
        $lines = [
            sprintf('[%d] %s (%s) - %s', $index, $this->symbol, $this->kind, strtoupper($this->direction)),
            sprintf('     Confluence %s of %s, directional %s, entry status: %s',
                $this->number($this->confluence, 1),
                $this->number($this->possible, 1),
                $this->number($this->directional, 1),
                $this->entryStatus,
            ),
            sprintf('     Timeframes %s, ADX %s, ATR %s (%s%% of price)',
                $this->aligned ? 'agree' : 'DISAGREE',
                $this->number($this->adx, 1),
                $this->number($this->atr, 2),
                $this->number($this->atrPct, 2),
            ),
            '     '.$this->structure,
        ];

        if ($this->complete()) {
            $lines[] = sprintf(
                '     Measured plan: entry %s, stop %s (beyond the %s at %s, touched %d), target %s (the %s at %s, touched %d), reward %s to 1',
                $this->number($this->entry, 5),
                $this->number($this->stop, 5),
                $this->stopLevel['kind'],
                $this->number((float) $this->stopLevel['price'], 5),
                $this->stopLevel['touches'],
                $this->number($this->target, 5),
                $this->targetLevel['kind'],
                $this->number((float) $this->targetLevel['price'], 5),
                $this->targetLevel['touches'],
                $this->number($this->rewardRatio, 2),
            );
        } else {
            // Said plainly rather than left out, because "no measured plan" is a reason to
            // pass on a candidate and the model should be able to give it.
            $lines[] = '     No measured plan: '.($this->stop === null
                ? 'no level behind price to put a stop beyond.'
                : 'no level ahead of price to aim at.');
        }

        $lines[] = '     Factors met: '.$this->why;

        return implode("\n", $lines);
    }

    private function number(?float $value, int $decimals): string
    {
        return $value === null ? 'unavailable' : rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
    }
}
