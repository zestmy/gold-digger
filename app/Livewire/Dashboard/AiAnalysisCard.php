<?php

namespace App\Livewire\Dashboard;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\Trade;
use App\Services\Ai\PairAnalyst;
use App\Services\News\NewsBlackout;
use App\Services\Strategy\MarketContext;
use App\Services\Strategy\SymbolResolver;
use App\Services\Strategy\TradingSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * AI Analysis Card
 *
 * Explains the current state in prose, in two explicitly separated halves.
 *
 * ## Not on a poll
 *
 * Every other card on this dashboard refreshes itself. This one does not, because every
 * refresh is a paid API call. It generates on demand and caches against the newest bar, so
 * a dashboard left open all day costs one call per bar at most instead of one per poll.
 * A card that quietly bills you for being on screen would be a bad card.
 */
class AiAnalysisCard extends Component
{
    public bool $configured = false;

    public bool $loading = false;

    public ?string $headline = null;

    public ?string $reading = null;

    public ?string $outlook = null;

    public ?string $error = null;

    public ?string $generatedAt = null;

    public function mount(): void
    {
        $this->configured = app(PairAnalyst::class)->configured();

        // Show a cached analysis immediately if one exists, but never generate on mount -
        // that would bill a page load.
        $this->hydrateFromCache();
    }

    /**
     * Generate a fresh analysis. Wired to a button, never to wire:poll.
     */
    public function analyse(bool $force = false): void
    {
        $analyst = app(PairAnalyst::class);

        if (! $analyst->configured()) {
            $this->error = 'No API key configured.';

            return;
        }

        [$context, $situation, $cacheKey] = $this->gather();

        if ($force) {
            Cache::forget($cacheKey);
        }

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            $this->apply($cached);

            return;
        }

        $result = $analyst->analyse($context, $situation);

        if (! $result['ok']) {
            $this->error = $result['error'];
            $this->headline = $this->reading = $this->outlook = null;

            return;
        }

        $payload = [
            'headline' => $result['analysis']->headline,
            'reading' => $result['analysis']->reading,
            'outlook' => $result['analysis']->outlook,
            'generated_at' => now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $payload, now()->addMinutes((int) config('ai.cache_minutes')));

        $this->apply($payload);
    }

    public function refresh(): void
    {
        $this->analyse(force: true);
    }

    private function hydrateFromCache(): void
    {
        if (! $this->configured) {
            return;
        }

        [, , $cacheKey] = $this->gather();
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            $this->apply($cached);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function apply(array $payload): void
    {
        $this->headline = $payload['headline'] ?? null;
        $this->reading = $payload['reading'] ?? null;
        $this->outlook = $payload['outlook'] ?? null;
        $this->error = null;
        $this->generatedAt = isset($payload['generated_at'])
            ? Carbon::parse($payload['generated_at'])->diffForHumans()
            : null;
    }

    /**
     * Assemble exactly what the analyst is allowed to know.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
     */
    private function gather(): array
    {
        $userId = Auth::id();

        $strategy = Strategy::where('user_id', $userId)->orderByDesc('is_active')->orderBy('id')->first();
        $settings = BotSettings::where('user_id', $userId)->first();
        $heartbeat = BotHeartbeat::where('user_id', $userId)->orderByDesc('last_seen_at')->first();

        if ($strategy === null) {
            return [['warm' => false], [], "ai.analysis.{$userId}.none"];
        }

        // Same fallback as the trend card: an offline executor does not erase the bars it
        // already pushed, and describing "no data" when there is data would be wrong.
        $accountId = $heartbeat?->broker_account_id
            ?? BrokerAccount::where('user_id', $userId)->where('is_active', true)->value('id');

        $spec = app(SymbolResolver::class)->for($accountId, $strategy->symbol, $heartbeat);
        $context = app(MarketContext::class)->for($strategy, $accountId, $spec['symbol']);

        $clock = app(TradingSession::class);
        $blackout = app(NewsBlackout::class);
        $now = Carbon::now('UTC');
        $currencies = $blackout->currenciesFor((string) $strategy->symbol);

        $newsObjection = $blackout->objection($settings, $currencies, $now);

        $situation = [
            'trading_enabled' => (bool) ($settings?->is_active ?? false),
            'session' => $clock->isOpen($settings?->allowed_sessions, $now)
                ? 'open ('.implode(', ', $clock->active($now)).')'
                : 'closed for this account',
            'news' => match ($newsObjection) {
                NewsBlackout::REASON_BLACKOUT => 'blackout - a high-impact release is inside the window',
                NewsBlackout::REASON_STALE => 'calendar unavailable, so entries are held',
                default => ($settings?->news_filter_enabled ?? false) ? 'clear' : 'filter disabled',
            },
            'adx_threshold' => (float) $strategy->adx_threshold,
            'open_positions' => Trade::where('user_id', $userId)->whereIn('status', ['open', 'partially_closed'])->count(),
            'max_positions' => (int) ($settings?->max_concurrent_trades ?? 0),
            'recent_skips' => Signal::whereHas('strategy', fn ($q) => $q->where('user_id', $userId))
                ->orderByDesc('generated_at')
                ->limit(6)
                ->get()
                ->map(fn (Signal $s) => sprintf(
                    '%s %s -> %s',
                    $s->generated_at?->format('D H:i') ?? '?',
                    strtoupper((string) $s->direction),
                    $s->skip_reason ?? 'TRADED',
                ))
                ->all(),
        ];

        // Keyed on the newest bar, so the analysis regenerates when the picture actually
        // changes rather than on a timer.
        $stamp = $context['last_bar_at'] instanceof Carbon
            ? $context['last_bar_at']->timestamp
            : 'cold';

        return [$context, $situation, "ai.analysis.{$userId}.{$stamp}"];
    }

    public function render()
    {
        return view('livewire.dashboard.ai-analysis-card');
    }
}
