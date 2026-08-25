<?php

namespace App\Livewire;

use App\Services\Strategy\TradingSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Session Bar
 *
 * Which market is open, and how long it has left. Fixed to the top of every page.
 *
 * ## Why this is not a card on the dashboard
 *
 * Session is the one piece of context that changes the meaning of everything else on
 * screen. A signal declined at 03:00 UTC and the same signal declined at 09:00 UTC are
 * different events - the first is the system working, the second is worth investigating -
 * and a trader reading a page cannot tell them apart without knowing what time it is in
 * the market rather than on their wall.
 *
 * Putting it on the dashboard means it is absent from every page where a decision is
 * actually made. So it goes in the frame.
 *
 * ## Three clocks, on purpose
 *
 * Sessions are defined in UTC, the reader lives somewhere, and the broker's server runs on
 * its own clock in Athens. Those are three different numbers and this project has already
 * been bitten by conflating two of them - the daily break landing at 22:00 UTC in winter
 * and 21:00 in summer was what proved the server runs EET/EEST rather than a fixed offset.
 * Showing UTC beside local time is what stops that confusion recurring in a support
 * conversation.
 */
class SessionBar extends Component
{
    /** Names worth showing, in the order a trading day runs. */
    private const ORDER = ['sydney', 'asian', 'london', 'overlap', 'newyork'];

    /**
     * Redraw when the clock ticks past something interesting.
     *
     * Polled rather than pushed: the alternative is a websocket for a value that changes
     * meaningfully a handful of times a day.
     */
    #[On('session-tick')]
    public function refresh(): void
    {
        // The render does the work. This exists so the event has somewhere to land.
    }

    public function render()
    {
        $now = Carbon::now('UTC');
        $sessions = app(TradingSession::class);

        $active = $sessions->active($now);

        $windows = [];

        foreach (self::ORDER as $name) {
            $isActive = in_array($name, $active, true);

            $windows[] = [
                'name' => $name,
                'label' => ucfirst($name === 'asian' ? 'Tokyo' : $name),
                'active' => $isActive,
                // Only for what is open: "closes in" on a shut market is a countdown to
                // something that is not going to happen.
                'closes_in' => $isActive ? $this->closesIn($name, $now) : null,
                'opens_in' => $isActive ? null : $this->opensIn($name, $now),
            ];
        }

        return view('livewire.session-bar', [
            'windows' => $windows,
            'utc' => $now,
            // Null when the reader has not chosen one; the view then shows only UTC rather
            // than showing the same number twice under two labels.
            'zone' => Auth::user()?->hasChosenZone() ? Auth::user()->zone() : null,
            'weekend' => $now->isSaturday() || ($now->isSunday() && $now->hour < 21) || ($now->isFriday() && $now->hour >= 21),
        ]);
    }

    /**
     * Windows are UTC hour pairs and may wrap midnight, which is why this is arithmetic
     * rather than a Carbon diff against a stored datetime.
     */
    private function closesIn(string $name, Carbon $now): string
    {
        [, $end] = $this->window($name);

        $close = $now->copy()->setTime($end, 0);

        if ($close->lessThanOrEqualTo($now)) {
            $close->addDay();
        }

        return $this->humanise($now->diffInMinutes($close));
    }

    private function opensIn(string $name, Carbon $now): string
    {
        [$start] = $this->window($name);

        $open = $now->copy()->setTime($start, 0);

        if ($open->lessThanOrEqualTo($now)) {
            $open->addDay();
        }

        return $this->humanise($now->diffInMinutes($open));
    }

    /**
     * @return array{int, int}
     */
    private function window(string $name): array
    {
        return match ($name) {
            'sydney' => [21, 6],
            'asian' => [23, 8],
            'london' => [7, 16],
            'overlap' => [12, 16],
            default => [12, 21],
        };
    }

    private function humanise(int $minutes): string
    {
        $minutes = max(0, $minutes);

        if ($minutes < 60) {
            return "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? "{$hours}h" : "{$hours}h {$rest}m";
    }
}
