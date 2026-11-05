<?php

namespace App\Providers;

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
        // Mobile chat polling: per Sanctum token (falls back to IP). 30/min ≈ one call every 2s at most.
        $key = fn ($r) => 'tok:'.($r->user()?->currentAccessToken()?->id ?? $r->ip());
        \Illuminate\Support\Facades\RateLimiter::for('chat-sync', fn ($r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by($key($r)));
        \Illuminate\Support\Facades\RateLimiter::for('chat', fn ($r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(90)->by($key($r)));
        \Illuminate\Support\Facades\RateLimiter::for('number-onboard', fn ($r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by($key($r))); // Add number flow (create / request_code / verify_code)
        // Client portal free-form + template send: 30/min per client (or IP if unauthenticated somehow).
        \Illuminate\Support\Facades\RateLimiter::for('client-send', fn ($r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('cs:'.($r->user()?->getAuthIdentifier() ?? $r->ip())));
    }
}
