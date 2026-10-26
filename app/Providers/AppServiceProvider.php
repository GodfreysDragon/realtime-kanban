<?php

namespace App\Providers;

use App\Services\BoardSequence;
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
        //
    }
}
