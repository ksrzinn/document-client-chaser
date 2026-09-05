<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
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
        Vite::prefetch(concurrency: 3);

        RateLimiter::for('client-request', function ($request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('client-upload', function ($request) {
            return [
                Limit::perMinute(10)->by('upload-req:'.hash('sha256', (string) $request->route('token'))),
                Limit::perMinute(30)->by('upload-ip:'.$request->ip()),
            ];
        });
    }
}
