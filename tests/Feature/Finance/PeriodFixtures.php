<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Services\PeriodCloseService;

/**
 * Shared scaffolding for the four fiscal-period suites.
 *
 * Deliberately dumb, in the taste of tests/Unit/Finance/FinanceFixtures: it
 * assembles rows and nothing else. Every expected count, message and severity is
 * spelled out in the test that asserts it.
 */
trait PeriodFixtures
{
    protected function periods(): PeriodCloseService
    {
        return app(PeriodCloseService::class);
    }

    protected function period(int $year, int $month): FiscalPeriod
    {
        return FiscalPeriod::query()->firstOrCreate(
            ['year' => $year, 'month' => $month],
            ['status' => 'open'],
        );
    }

    /**
     * Close every period strictly before (year, month) straight in the table.
     *
     * Written directly rather than through close(), because these suites test
     * ONE item at a time and closing five months through the service would drag
     * every other item's fixtures along with them.
     */
    protected function closeEverythingBefore(int $year, int $month): void
    {
        FiscalPeriod::query()
            ->whereRaw('(year * 100 + month) < ?', [$year * 100 + $month])
            ->update(['status' => 'closed']);
    }

    protected function setPeriodStatus(int $year, int $month, string $status): void
    {
        FiscalPeriod::query()->where('year', $year)->where('month', $month)->update(['status' => $status]);
    }

    /** The person who closes the books. Services never check permissions. */
    protected function closerUser(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'closer@test.local'],
            ['name' => 'Sri Wahyuni', 'password' => 'password', 'is_active' => true],
        );
    }

    /** The higher bar: whoever may reopen. */
    protected function reopenerUser(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'direktur@test.local'],
            ['name' => 'Bambang Sutrisno', 'password' => 'password', 'is_active' => true],
        );
    }

    // ------------------------------------------------------------- checklist

    /** @return array<string, mixed> */
    protected function checklistItem(int $year, int $month, string $key): array
    {
        foreach ($this->periods()->checklist($year, $month) as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        $this->fail("Checklist has no item [{$key}].");
    }

    protected function assertItem(int $year, int $month, string $key, string $severity, string $status): array
    {
        $item = $this->checklistItem($year, $month, $key);

        $this->assertSame($severity, $item['severity'], "Severity of [{$key}]: {$item['detail']}");
        $this->assertSame($status, $item['status'], "Status of [{$key}]: {$item['detail']}");

        return $item;
    }

    // ------------------------------------------------------- source documents

    protected function makePayrollRun(int $year, int $month, string $status = 'draft', string $code = 'PYR/TEST/001'): int
    {
        return DB::table('hr_payroll_runs')->insertGetId([
            'code' => $code,
            'period_year' => $year,
            'period_month' => $month,
            'run_type' => 'regular',
            'payment_date' => sprintf('%04d-%02d-25', $year, $month),
            'total_gross' => 196270346,
            'total_deductions' => 0,
            'total_net' => 196270346,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function makeDepreciationRun(int $year, int $month, string $status = 'draft', string $code = 'DPR/TEST/001'): int
    {
        return DB::table('ast_depreciation_runs')->insertGetId([
            'code' => $code,
            'period_year' => $year,
            'period_month' => $month,
            'status' => $status,
            'total_amount' => 25125000,
            'posted_at' => $status === 'posted' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** One employee — enough to make "payroll bulan ini" an expectation. */
    protected function makeEmployee(): int
    {
        return DB::table('hr_employees')->insertGetId([
            'code' => 'EMP-'.str_pad((string) (DB::table('hr_employees')->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => 'Agus Prasetyo',
            'nik_ktp' => str_pad((string) (3175000000000000 + DB::table('hr_employees')->count() + 1), 16, '0'),
            'gender' => 'male',
            'birth_date' => '1990-04-11',
            'ptkp_status' => 'K/1',
            'join_date' => '2024-01-05',
            'employment_type' => 'tetap',
            'position' => 'Mandor',
            'department' => 'proyek',
            'base_salary' => 8500000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** One depreciable asset — enough to make "penyusutan bulan ini" an expectation. */
    protected function makeDepreciableAsset(): int
    {
        $categoryId = DB::table('ast_categories')->insertGetId([
            'code' => 'CAT-ALAT',
            'name' => 'Alat Berat',
            'useful_life_months_default' => 96,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('ast_assets')->insertGetId([
            'code' => 'AST-2026-0001',
            'name' => 'Excavator Komatsu PC200',
            'category_id' => $categoryId,
            'acquisition_date' => '2026-01-10',
            'acquisition_cost' => 1200000000,
            'salvage_value' => 0,
            'useful_life_months' => 96,
            'depreciation_start_date' => '2026-02-01',
            'accumulated_depreciation' => 0,
            'book_value' => 1200000000,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
