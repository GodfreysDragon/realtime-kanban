<?php

namespace App\Providers;

use App\Services\BoardSequence;
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
        // One per request (or queued job), so the header lists only what
        // this request broadcast.
        $this->app->scoped(BoardSequence::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('board-api', fn (Request $request) => Limit::perMinute(config('kanban.api_requests_per_minute'))
            ->by($request->user()?->id ?: $request->ip()));
    }
}
