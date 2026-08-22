<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production bootstrap: master/reference data + RBAC + one admin user from
 * ERP_ADMIN_* env. Zero demo documents — demo data (customers, projects,
 * invoices, stock, ...) is owned by DatabaseSeeder and the module
 * <Name>DatabaseSeeders, which must never run in production.
 *
 * Usage: php artisan db:seed --class=ProductionSeeder --force
 *
 * Idempotent: every seeder below uses updateOrCreate/findOrCreate keyed by
 * natural code, so re-running is safe on first-time bootstrap. Requires
 * ERP_ADMIN_EMAIL and ERP_ADMIN_PASSWORD in the environment (AdminUserSeeder
 * aborts with a clear error when they are missing or weak).
 */
class ProductionSeeder extends Seeder
{
    /**
     * Order matters: roles need permissions, the admin user needs the admin
     * role, taxes look up chart-of-accounts rows.
     *
     * @var list<class-string>
     */
    protected array $seeders = [
        // Company profile (single row; edit the real details via the API).
        \Modules\Core\Database\Seeders\CoreDatabaseSeeder::class,

        // RBAC: permission catalogue, roles, then the single admin account
        // from ERP_ADMIN_* env. Deliberately NOT UserSeeder (demo users).
        \Modules\Iam\Database\Seeders\PermissionSeeder::class,
        \Modules\Iam\Database\Seeders\RoleSeeder::class,
        \Modules\Iam\Database\Seeders\AdminUserSeeder::class,

        // Finance master data: COA before taxes (coa_account_id lookups).
        // ProductionFiscalPeriodSeeder (not the demo FiscalPeriodSeeder):
        // current year, all months open, firstOrCreate — re-running never
        // reopens periods closed through the API.
        \Modules\Finance\Database\Seeders\ChartOfAccountsSeeder::class,
        \Modules\Finance\Database\Seeders\TaxSeeder::class,
        \Modules\Finance\Database\Seeders\ProductionFiscalPeriodSeeder::class,

        // Reference categories (categories only — no items, no assets).
        \Modules\Inventory\Database\Seeders\ItemCategorySeeder::class,
        \Modules\Assets\Database\Seeders\AssetCategorySeeder::class,
    ];

    public function run(): void
    {
        foreach ($this->seeders as $class) {
            // Class-exists guard, same as DatabaseSeeder: a module removed
            // from the deployment simply drops out of the seed run.
            if (class_exists($class)) {
                $this->call($class);
            }
        }
    }
}
