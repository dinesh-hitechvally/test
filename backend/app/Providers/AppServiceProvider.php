<?php

namespace App\Providers;

use App\Contracts\AiOpinionProvider;
use App\Contracts\PriceHistorySource;
use App\Events\ScrapeFinished;
use App\Events\StockPricesUpdated;
use App\Events\TaskFailed;
use App\Events\UserLoggedIn;
use App\Listeners\AlertTaskFailure;
use App\Listeners\FlagPriceQualityIssues;
use App\Listeners\RecalculateUpdatedStocks;
use App\Listeners\RecordEmailSending;
use App\Listeners\RecordEmailSent;
use App\Listeners\RecordLoginHistory;
use App\Listeners\RecordScrapeLog;
use App\Services\Ai\GroqOpinionProvider;
use App\Services\DataSources\ShareSansar\SharesansarHistoryService;
use App\Services\Mail\EmailLogService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
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
     * One shared instance per process. EmailLogService remembers which emails
     * are mid-send, so the exception handler can mark them failed.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        EmailLogService::class => EmailLogService::class,
    ];

    /**
     * The whole event workflow at a glance — what happens after what.
     * Registered explicitly (auto-discovery is off in bootstrap/app.php)
     * listener: that would stop it with no error anywhere. All listeners
     * run synchronously.
     * with no error anywhere. All listeners run synchronously.
     *
     * @var array<class-string, list<class-string>>
     */
    private const LISTENERS = [
        StockPricesUpdated::class => [FlagPriceQualityIssues::class],
        ScrapeFinished::class => [RecordScrapeLog::class],
        TaskFailed::class => [AlertTaskFailure::class],
        UserLoggedIn::class => [RecordLoginHistory::class],
        // Laravel's own mail events — every email, whatever sent it.
        MessageSending::class => [RecordEmailSending::class],
        MessageSent::class => [RecordEmailSent::class],
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
