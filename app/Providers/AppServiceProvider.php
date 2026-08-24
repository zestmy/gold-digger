<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use App\Services\News\CalendarSource;
use App\Services\News\ForexFactoryCalendar;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Where the news blackout's calendar comes from. Bound rather than type-hinted
        // concretely so a feed that disappears can be replaced without touching the importer,
        // and so tests can swap in a fixture without going near the network.
        $this->app->bind(CalendarSource::class, ForexFactoryCalendar::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the UserObserver to handle automatic setup
        // when new users register (creates BotSettings + default Strategy)
        User::observe(UserObserver::class);
    }
}
