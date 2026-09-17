<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
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
        // own reset-password page instead. Same FRONTEND_URL env var
        // config/cors.php already reads.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');

            return "{$frontendUrl}/reset-password?token={$token}&email=".urlencode($user->email);
        });
    }
}
