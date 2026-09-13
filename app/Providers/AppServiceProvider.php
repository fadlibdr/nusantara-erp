<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Modules\Iam\Support\IntegrationRate;

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

        /*
         * Global API rate limit, applied by $middleware->throttleApi() in
         * bootstrap/app.php.
         *
         * P-3d memisahkannya menjadi DUA ember (ledger pemilik
         * ROADMAP-HASHMICRO §5 baris 10: "laju token integrasi → 300 per
         * menit"): token pribadi mendapat 300/menit per TOKEN, semua yang lain
         * tetap 120/menit dengan kunci yang sama persis seperti sebelumnya —
         * id pengguna bila ada, IP bila tidak. Aturan dan alasannya, termasuk
         * harga satu pencarian token per permintaan, ada di
         * Modules\Iam\Support\IntegrationRate.
         */
        RateLimiter::for('api', fn (Request $request): Limit => IntegrationRate::limitFor($request));
    }
}
