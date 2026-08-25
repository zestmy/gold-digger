<?php

namespace App\View\Components;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

/**
 * A timestamp, shown where the reader is.
 *
 * ## Conversion happens here and nowhere else
 *
 * Everything in this application is stored and reasoned about in UTC. That is not an
 * accident to be worked around - a trading system whose stored times move with a user
 * setting produces bugs that surface twice a year and are close to unfindable when they
 * do. So the timezone is applied on the way to the screen, once, in this class.
 *
 * The rule that keeps it true: no query, comparison, or piece of logic ever calls
 * `zone()`. If a timezone is needed to decide something, that decision is wrong.
 *
 * ## The UTC is still there
 *
 * On the title attribute, so hovering any time on any page shows what the database holds.
 * Support conversations about a trading system are conducted in UTC, and a dashboard that
 * can only speak local time makes them harder rather than easier.
 */
class LocalTime extends Component
{
    public ?Carbon $moment;

    public string $zone;

    /**
     * @param  mixed  $value  A Carbon, a parseable string, or null
     * @param  string  $format  How to render it once converted
     * @param  bool  $relative  Show "3 minutes ago" instead - correct in any zone
     */
    public function __construct(
        mixed $value = null,
        public string $format = 'M d, H:i',
        public bool $relative = false,
        public string $empty = '—',
    ) {
        $this->zone = Auth::user()?->zone() ?? (string) config('app.timezone', 'UTC');

        $this->moment = match (true) {
            $value instanceof Carbon => $value,
            $value === null, $value === '' => null,
            // A string from a raw query or an array payload still renders, rather than
            // being a footgun that silently prints nothing.
            default => Carbon::parse($value),
        };
    }

    public function render()
    {
        return view('components.local-time');
    }
}
