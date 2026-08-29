<?php

namespace Modules\Procurement\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Procurement\Support\BidWeights;

class ProcurementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // P2 — refuse to boot on a misweighted bid-weight config: the weighted
        // tabulation is a signed procurement record, and a five-aspect split
        // that does not sum to 100 ranks vendors on a scale nobody agreed to.
        // Stop the app here rather than let the misweight reach a tabulation.
        BidWeights::assertValidConfig();

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::middleware('api')
            ->prefix('api/procurement')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
