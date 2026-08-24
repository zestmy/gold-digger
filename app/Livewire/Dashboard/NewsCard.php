<?php

namespace App\Livewire\Dashboard;

use App\Models\BotSettings;
use App\Models\EconomicEvent;
use App\Models\Strategy;
use App\Services\News\NewsBlackout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * News Card
 *
 * The high-impact releases this account's instrument is exposed to, and whether one of
 * them is currently blocking entries.
 *
 * The card exists mostly to make a previously invisible control legible. `news_filter_
 * enabled` has been a switched-on toggle in settings since the beginning with nothing
 * behind it; now that it gates trading, the reason a quiet hour is quiet needs to be
 * somewhere a person can see without reading `signals`.
 *
 * Stale data is surfaced as loudly as a blackout, because its consequence is the same -
 * entries are held - and its remedy is completely different.
 */
class NewsCard extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $upcoming = [];

    public bool $filterEnabled = false;

    public bool $stale = false;

    public bool $inBlackout = false;

    public ?string $nextEventTitle = null;

    public ?string $nextEventIn = null;

    public ?string $nextEventAt = null;

    /** @var array<int, string> */
    public array $currencies = [];

    public int $beforeMinutes = 0;

    public int $afterMinutes = 0;

    public function mount(): void
    {
        $this->refreshNews();
    }

    public function refreshNews(): void
    {
        $blackout = app(NewsBlackout::class);
        $now = Carbon::now('UTC');

        $settings = BotSettings::where('user_id', Auth::id())->first();
        $strategy = Strategy::where('user_id', Auth::id())
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        $this->filterEnabled = (bool) ($settings?->news_filter_enabled ?? false);
        $this->beforeMinutes = (int) ($settings?->news_blackout_before_minutes ?? 0);
        $this->afterMinutes = (int) ($settings?->news_blackout_after_minutes ?? 0);
        $this->currencies = $strategy ? $blackout->currenciesFor((string) $strategy->symbol) : [];
        $this->stale = $blackout->isStale();

        $objection = $blackout->objection($settings, $this->currencies, $now);
        $this->inBlackout = $objection === NewsBlackout::REASON_BLACKOUT;

        $next = $blackout->nextEvent($this->currencies, $now);
        $this->nextEventTitle = $next?->title;
        $this->nextEventAt = $next?->scheduled_at->format('D H:i').' UTC';
        $this->nextEventIn = $next?->scheduled_at->diffForHumans($now, ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]);

        // A day of context either side. Medium and low impact are included here even
        // though they gate nothing - "why was this hour choppy" is answered by them, and
        // omitting them would imply the calendar was empty.
        $this->upcoming = $this->currencies === [] ? [] : EconomicEvent::query()
            ->forCurrencies($this->currencies)
            ->whereIn('impact', ['high', 'medium'])
            ->between($now->copy()->subHours(2), $now->copy()->addDay())
            ->orderBy('scheduled_at')
            ->limit(6)
            ->get()
            ->map(fn (EconomicEvent $e) => [
                'title' => $e->title,
                'currency' => $e->currency,
                'impact' => $e->impact,
                'at' => $e->scheduled_at->format('D H:i'),
                'past' => $e->scheduled_at->isPast(),
                'actual' => $e->actual,
                'forecast' => $e->forecast,
            ])
            ->all();
    }

    public function render()
    {
        return view('livewire.dashboard.news-card');
    }
}
