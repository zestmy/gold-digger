<?php

namespace App\Models;

use App\Services\News\CotFeed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One week's Commitments of Traders reading for one market.
 *
 * The useful figure is not the raw position count - which varies by orders of magnitude
 * between gold and the Swiss franc, and is meaningless without knowing the market's usual
 * size - but where this week sits in its own history. "Speculators are 120,000 long" says
 * nothing; "more long than in 94% of the last two years" says something.
 */
class CotReport extends Model
{
    /** Beyond this the reading is old enough to be worth labelling rather than showing. */
    private const STALE_DAYS = 14;

    protected $fillable = [
        'market', 'report_date',
        'noncommercial_long', 'noncommercial_short',
        'commercial_long', 'commercial_short', 'open_interest', 'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * Net speculative positioning: long minus short.
     *
     * Non-commercial rather than commercial, because that is the category conventionally
     * read as directional. Commercials are largely hedging physical exposure, so their net
     * says more about a mining schedule than about a view.
     */
    public function net(): int
    {
        return $this->noncommercial_long - $this->noncommercial_short;
    }

    /**
     * The latest reading for an instrument, with its historical context.
     *
     * Null when the instrument has no futures market to have positioning in, which is a
     * great many of them and is not an error.
     *
     * @return array{
     *     net: int,
     *     direction: string,
     *     percentile: int,
     *     change: int|null,
     *     report_date: Carbon,
     *     stale: bool,
     *     summary: string
     * }|null
     */
    public static function contextFor(string $symbol): ?array
    {
        $market = CotFeed::marketFor($symbol);

        if ($market === null) {
            return null;
        }

        $history = static::where('market', $market)
            ->orderByDesc('report_date')
            ->limit(104)
            ->get();

        $latest = $history->first();

        if ($latest === null) {
            return null;
        }

        $nets = $history->map(fn (self $r) => $r->net())->values();
        $net = $latest->net();

        // Where this week sits against its own two-year range. A raw count is
        // uninterpretable across markets; a percentile is the same statement everywhere.
        $below = $nets->filter(fn (int $n) => $n < $net)->count();
        $percentile = $nets->count() > 1 ? (int) round($below / ($nets->count() - 1) * 100) : 50;

        $previous = $history->get(1);
        $change = $previous === null ? null : $net - $previous->net();

        $direction = $net > 0 ? 'net long' : ($net < 0 ? 'net short' : 'flat');
        $stale = $latest->report_date->lt(now()->subDays(self::STALE_DAYS));

        return [
            'net' => $net,
            'direction' => $direction,
            'percentile' => $percentile,
            'change' => $change,
            'report_date' => $latest->report_date,
            'stale' => $stale,
            // Phrased as a comparison rather than as "N% of range", which reads backwards
            // on a short: net -54,000 at the 92nd percentile means less short than usual,
            // and "net short, 92% of range" invites exactly the opposite reading.
            'summary' => sprintf(
                'Speculators are %s %s contracts - more long than %d%% of the last two years%s. Counted %s.',
                $direction,
                number_format(abs($net)),
                $percentile,
                $change === null
                    ? ''
                    : sprintf(', and %s %s on the week', $change >= 0 ? 'longer by' : 'shorter by', number_format(abs($change))),
                $latest->report_date->format('j M'),
            ),
        ];
    }
}
