<?php

namespace Tests\Unit\Finance;

use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Enums\PettyCashCategory;
use Modules\Finance\Models\Account;
use Tests\ErpTestCase;

/**
 * The category mappings must be TOTAL — a bon whose category maps to a
 * missing or non-postable account would fail at posting time, in front of a
 * site custodian, with a chart-of-accounts error they cannot act on.
 */
class PettyCashCategoryTest extends ErpTestCase
{
    use FinanceFixtures;

    public function test_every_category_maps_to_seeded_postable_expense_accounts_and_a_cost_bucket(): void
    {
        $this->seedLedger(2026);

        foreach (PettyCashCategory::cases() as $category) {
            $this->assertNotSame('', $category->label(), "label of {$category->value}");

            // Project bons hit HPP (5-xxxx), office bons hit opex (6-xxxx).
            $this->assertStringStartsWith('5-', $category->cogsAccountCode(), "cogs of {$category->value}");
            $this->assertStringStartsWith('6-', $category->opexAccountCode(), "opex of {$category->value}");

            foreach ([$category->cogsAccountCode(), $category->opexAccountCode()] as $code) {
                $account = Account::query()->where('code', $code)->first();

                $this->assertNotNull($account, "account {$code} for {$category->value} is missing from the seeded chart");
                $this->assertTrue($account->is_postable, "account {$code} for {$category->value} is a group");
            }

            $this->assertInstanceOf(CostCategory::class, $category->costCategory(), "cost bucket of {$category->value}");
        }
    }

    public function test_the_rap_buckets_line_up_with_what_each_category_buys(): void
    {
        // Spelled out pair by pair, so a future re-mapping is a conscious edit
        // here and not a silent drift of the cost-to-cost percentage.
        $this->assertSame(CostCategory::Material, PettyCashCategory::Material->costCategory());
        $this->assertSame(CostCategory::Labor, PettyCashCategory::Upah->costCategory());
        $this->assertSame(CostCategory::Equipment, PettyCashCategory::AlatBantu->costCategory());
        $this->assertSame(CostCategory::Overhead, PettyCashCategory::BbmTol->costCategory());
        $this->assertSame(CostCategory::Overhead, PettyCashCategory::Konsumsi->costCategory());
        $this->assertSame(CostCategory::Overhead, PettyCashCategory::Lainnya->costCategory());
    }
}
