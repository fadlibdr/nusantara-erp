<?php

namespace Modules\Inventory\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Console\Commands\InventoryMethodCheck;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::middleware('api')
            ->prefix('api/inventory')
            ->group(__DIR__.'/../Routes/api.php');

        // Run before changing accounting.perpetual_inventory in config/erp.php:
        // the inventory accounting method is an install-time election, and this
        // reports what a change would strand. Not scheduled — it answers a
        // question a deploy asks, not a recurring one.
        $this->commands([
            InventoryMethodCheck::class,
        ]);
    }
}
