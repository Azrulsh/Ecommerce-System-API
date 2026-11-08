<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(30)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('identity:'.hash('sha256', strtolower(is_string($request->input('email')) ? trim($request->input('email')) : '').'|'.$request->ip())),
        ]);
    }
}
