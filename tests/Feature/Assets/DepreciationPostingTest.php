<?php

namespace Tests\Feature\Assets;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Services\DepreciationService;
use Modules\Finance\Models\JournalLine;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Depreciation reaching the general ledger.
 *
 * Posting a run used to update accumulated_depreciation and book_value on the
 * assets and stop there, so depreciation expense never appeared in the books and
 * company-owned plant was free in every project P&L — which structurally favours
 * renting in any make-or-buy comparison.
 */
class DepreciationPostingTest extends ErpTestCase
{
    use FinanceFixtures;

    private DepreciationService $depreciation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->depreciation = app(DepreciationService::class);
    }

    private function category(array $overrides = []): AssetCategory
    {
        return AssetCategory::query()->create(array_merge([
            'code' => 'CAT-'.str()->random(4),
            'name' => 'Alat Berat',
            'useful_life_months_default' => 96,
            'depreciation_account_hint' => '6-3100',
            'accum_account_hint' => '1-2410',
        ], $overrides));
    }

    private function asset(AssetCategory $category, float $cost = 96_000_000): Asset
    {
        return Asset::query()->create([
            'code' => 'AST-'.str()->random(5),
            'name' => 'Excavator Uji',
            'category_id' => $category->id,
            'acquisition_date' => '2025-01-01',
            'depreciation_start_date' => '2025-01-01',
            'acquisition_cost' => $cost,
            'useful_life_months' => 96,
            'salvage_value' => 0,
            'accumulated_depreciation' => 0,
            'book_value' => $cost,
            'status' => 'available',
        ]);
    }

    private function postedRun(): void
    {
        $run = $this->depreciation->runForPeriod(2026, 3);
        $this->depreciation->post($run);
    }

    public function test_posting_a_run_charges_depreciation_to_the_ledger(): void
    {
        $this->asset($this->category());

        $this->assertSame(0, JournalLine::query()->count());

        $this->postedRun();

        $lines = JournalLine::query()
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->get(['fin_accounts.code', 'fin_journal_lines.debit', 'fin_journal_lines.credit'])
            ->keyBy('code');

        $this->assertArrayHasKey('6-3100', $lines->all(), 'depreciation expense must be booked');
        $this->assertArrayHasKey('1-2410', $lines->all(), 'accumulated depreciation must be credited');
        $this->assertEqualsWithDelta(1_000_000, (float) $lines['6-3100']->debit, 0.01);
        $this->assertEqualsWithDelta(1_000_000, (float) $lines['1-2410']->credit, 0.01);
    }

    public function test_the_entry_balances(): void
    {
        $this->asset($this->category());
        $this->asset($this->category(['accum_account_hint' => '1-2510']), 48_000_000);

        $this->postedRun();

        $difference = JournalLine::query()->selectRaw('SUM(debit) - SUM(credit) as diff')->value('diff');

        $this->assertSame(0, (int) round((float) $difference * 100));
    }

    /**
     * Two asset classes must credit two different accumulated accounts —
     * collapsing them misstates both, invisibly, because they sit together
     * under 1-2000 on the balance sheet.
     */
    public function test_each_asset_class_credits_its_own_accumulated_account(): void
    {
        $this->asset($this->category());                                       // 1-2410
        $this->asset($this->category(['accum_account_hint' => '1-2510']), 48_000_000);

        $this->postedRun();

        $credits = JournalLine::query()
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_journal_lines.credit', '>', 0)
            ->pluck('fin_accounts.code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['1-2410', '1-2510'], $credits);
    }

    /**
     * A category with no accounts is refused rather than posted to a default.
     * Guessing an accumulated account misstates two classes at once.
     */
    public function test_a_category_without_accounts_is_refused(): void
    {
        $this->asset($this->category(['accum_account_hint' => null]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/belum memiliki akun penyusutan/');

        $this->postedRun();
    }

    /** Depreciation belongs to the month it charges, not the day it was posted. */
    public function test_the_entry_is_dated_to_the_period_depreciated(): void
    {
        $this->asset($this->category());

        $this->postedRun();

        $date = DB::table('fin_journals')
            ->where('reference_type', 'depreciation_run')
            ->value('journal_date');

        $this->assertStringStartsWith('2026-03-31', (string) $date);
    }
}
