<?php

namespace App\Providers;

use App\Contracts\AiOpinionProvider;
use App\Contracts\PriceHistorySource;
use App\Events\CronTaskFailed;
use App\Events\ScrapeFinished;
use App\Events\StockPricesUpdated;
use App\Listeners\AlertCronFailure;
use App\Listeners\RecalculateUpdatedStocks;
use App\Listeners\RecordScrapeLog;
use App\Services\Ai\GroqOpinionProvider;
use App\Services\DataSources\ShareSansar\SharesansarHistoryService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The one place that decides which concrete implementation backs each
     * contract — swapping the AI provider or the primary history source is
     * a change here, not in every class that uses it.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        AiOpinionProvider::class => GroqOpinionProvider::class,
        PriceHistorySource::class => SharesansarHistoryService::class,
    ];

    /**
     * The whole event workflow at a glance — what happens after what.
     * Registered explicitly (auto-discovery is off in bootstrap/app.php)
     * so a stale `event:cache` manifest can never silently drop a
     * listener: that would stop recalculation after every price update
     * with no error anywhere. All listeners run synchronously.
     *
     * @var array<class-string, list<class-string>>
     */
    private const LISTENERS = [
        StockPricesUpdated::class => [RecalculateUpdatedStocks::class],
        ScrapeFinished::class => [RecordScrapeLog::class],
        CronTaskFailed::class => [AlertCronFailure::class],
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (self::LISTENERS as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }

        // This is an API-only backend behind a separate Vue SPA — the
        // built-in reset notification would otherwise link to a backend
        // route that doesn't exist here, so it's pointed at the frontend's
        // own reset-password page instead. Same FRONTEND_URL config/cors.php
        // already reads.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontendUrl = rtrim(config('app.frontend_url'), '/');

            return "{$frontendUrl}/reset-password?token={$token}&email=".urlencode($user->email);
        });
    }
}
