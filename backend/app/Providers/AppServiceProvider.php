<?php

namespace App\Providers;

use App\Contracts\AiOpinionProvider;
use App\Contracts\PriceHistorySource;
use App\Services\Ai\GroqOpinionProvider;
use App\Services\MarketData\SharesansarHistoryService;
use Illuminate\Auth\Notifications\ResetPassword;
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
