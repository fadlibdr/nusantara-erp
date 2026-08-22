<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // MySQL < 8.0.17 / older MariaDB compatible index lengths.
        Schema::defaultStringLength(191);

        // Behind a TLS-terminating reverse proxy: generate https:// URLs.
        // Read via config (not env()) so config:cache works in production.
        if (config('erp.security.force_https')) {
            URL::forceScheme('https');
        }

        // Global API rate limit, applied by $middleware->throttleApi() in
        // bootstrap/app.php. Keyed by user id when authenticated, IP otherwise.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('erp.security.api_rate_limit', 120))
                ->by($request->user()?->id ?? $request->ip());
        });
    }
}
