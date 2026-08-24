<?php

namespace App\Livewire\Dashboard;

use App\Models\BotSettings;
use App\Services\Strategy\TradingSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Session Card
 *
 * Which FX sessions are open, and whether this account is allowed to trade in them.
 *
 * `session_closed` is one of the commonest skip reasons and the least self-explanatory
 * one: the sessions are UTC hours held in `bot_settings.allowed_sessions`, so working out
 * why nothing is happening means knowing the current UTC hour and the configured list.
 * That is a question the dashboard should answer rather than pose.
 *
 * The distinction the card is built around is *open* versus *allowed*. London can be open
 * while this account trades only the overlap, and those are different facts with different
 * fixes - wait, or change the setting.
 */
class SessionCard extends Component
{
    /** @var array<int, string> Sessions currently open, whether or not this account trades them. */
    public array $openSessions = [];

    /** @var array<int, string> Sessions this account is configured to trade. */
    public array $allowedSessions = [];

    /** Is at least one allowed session open right now? */
    public bool $tradingWindowOpen = false;

    /** True when no restriction is configured, which reads as "always allowed". */
    public bool $unrestricted = false;

    public ?string $nextOpenAt = null;

    public ?string $nextOpenIn = null;

    public string $utcTime = '';

    public function mount(): void
    {
        $this->refreshSessions();
    }

    public function refreshSessions(): void
    {
        $clock = app(TradingSession::class);
        $now = Carbon::now('UTC');

        $settings = BotSettings::where('user_id', Auth::id())->first();
        $allowed = $settings?->allowed_sessions;

        $this->utcTime = $now->format('H:i').' UTC';
        $this->openSessions = $clock->active($now);
        $this->allowedSessions = is_array($allowed) ? $allowed : [];
        $this->unrestricted = $this->allowedSessions === [];
        $this->tradingWindowOpen = $clock->isOpen($allowed, $now);

        $next = $clock->nextOpenAt($allowed, $now);

        $this->nextOpenAt = $next?->format('H:i').($next ? ' UTC' : '');
        // diffForHumans against the same clock instant, so a card rendered a second later
        // cannot say "in 0 minutes" while the boundary has not moved.
        $this->nextOpenIn = $next?->diffForHumans($now, ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]);
    }

    public function render()
    {
        return view('livewire.dashboard.session-card');
    }
}
