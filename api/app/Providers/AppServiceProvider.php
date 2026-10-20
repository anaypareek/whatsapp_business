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
    }
}
