<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\WatchedDeadlines;
use Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Base class for tests that exercise business logic against a real (in-memory)
 * schema. phpunit.xml pins DB_DATABASE=:memory: and CACHE_STORE=array, so nothing
 * here can touch the development database or leak cached settings between tests.
 */
abstract class ErpTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The settings resolver memoises per instance; the container is rebuilt
        // per test, but be explicit so an override in one test cannot leak.
        app(SettingService::class)->flush();

        // The schema memo is per PROCESS, and tests rebuild the in-memory
        // database per class — a memo carried across that boundary reports
        // tables that no longer exist and crashes the degradation tests that
        // drop one on purpose.
        WatchedDeadlines::flushSchemaMemo();
    }

    /**
     * Chart of accounts + an open fiscal period for every month the tests use.
     * Required by anything that posts a journal.
     */
    protected function seedLedger(int $year = 2026): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $this->openFiscalYear($year);
    }

    protected function openFiscalYear(int $year): void
    {
        for ($month = 1; $month <= 12; $month++) {
            FiscalPeriod::query()->updateOrCreate(
                ['year' => $year, 'month' => $month],
                ['status' => 'open'],
            );
        }
    }

    /**
     * A user holding every permission, for endpoint tests.
     */
    protected function adminUser(): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('admin', 'web');
        $role->syncPermissions(Permission::query()->where('guard_name', 'web')->get());

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        return $user;
    }

    /**
     * Give an ERP parameter a value for the duration of one test.
     *
     * A key the registry describes goes through SettingService, exactly as the
     * settings screen and the API do — including every rule the registry
     * enforces, so a test cannot store a value an operator could not.
     *
     * A key config/erp.php defines but the registry deliberately does NOT is an
     * install-time constant, and is applied to the config layer instead, which is
     * precisely where a deploy sets it. accounting.perpetual_inventory is the
     * case that matters: it elects the inventory accounting method and was
     * withdrawn from the registry in audit A2 because one checkbox flip corrupted
     * the ledger, but the engine still has to be exercised under both methods.
     * (That it can no longer be written through the service or the API is
     * asserted directly, in SettingServiceTest and SettingValidationTest.)
     *
     * A key neither knows is a typo and still fails loudly.
     */
    protected function setSetting(string $key, mixed $value): void
    {
        $settings = app(SettingService::class);

        if (array_key_exists($key, $settings->editableKeys())) {
            $settings->set($key, $value);

            return;
        }

        if (! config()->has("erp.{$key}")) {
            throw new InvalidArgumentException(
                "Setting [{$key}] is not editable and config/erp.php does not define it either.",
            );
        }

        // null means "restore the shipped default". Read that back from the file,
        // not from the config the running test may already have changed.
        config(["erp.{$key}" => $value ?? Arr::get(require config_path('erp.php'), $key)]);
    }
}
