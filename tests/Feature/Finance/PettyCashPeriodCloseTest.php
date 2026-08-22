<?php

namespace Tests\Feature\Finance;

use Modules\Finance\Services\PeriodCloseService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Kas kecil in the close checklist: a DRAFT bon or kasbon pins its date inside
 * the month and hard-blocks the close through dangling_documents; an ISSUED
 * kasbon deliberately does not — its advance is already in the ledger and the
 * unsettled balance is a genuinely outstanding receivable, correctly stated.
 */
class PettyCashPeriodCloseTest extends ErpTestCase
{
    use FinanceFixtures;
    use PeriodFixtures;
    use PettyCashFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
    }

    public function test_a_draft_voucher_dated_in_the_month_blocks_the_close_until_it_is_posted(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        $voucher = $this->makeVoucher($fund, ['voucher_date' => '2026-06-30', 'amount' => 250000]);

        // The last-day-of-month date is exactly the shape the half-open window
        // exists for — a BETWEEN scan missed it.
        $item = $this->assertItem(2026, 6, 'dangling_documents', PeriodCloseService::BLOCK, PeriodCloseService::FAIL);
        $this->assertStringContainsString($voucher->code, $item['detail']);
        $this->assertSame('fin_petty_cash_vouchers', $item['sources'][0]['source']);
        $this->assertSame('Voucher kas kecil', $item['sources'][0]['label']);

        $this->vouchers()->post($voucher, $this->custodianUser());

        $this->assertItem(2026, 6, 'dangling_documents', PeriodCloseService::BLOCK, PeriodCloseService::OK);
    }

    public function test_a_draft_kasbon_blocks_the_close_but_an_issued_one_does_not(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        $kasbon = $this->makeKasbon($fund, $this->makeEmployee(), ['advance_date' => '2026-06-15']);

        $item = $this->assertItem(2026, 6, 'dangling_documents', PeriodCloseService::BLOCK, PeriodCloseService::FAIL);
        $this->assertSame('fin_kasbons', $item['sources'][0]['source']);
        $this->assertStringContainsString($kasbon->code, $item['detail']);

        // Issued: the advance is posted (Dr 1-1370) and the open receivable is
        // a true period-end fact — June may close over it.
        $this->kasbons()->issue($kasbon, $this->custodianUser());

        $this->assertItem(2026, 6, 'dangling_documents', PeriodCloseService::BLOCK, PeriodCloseService::OK);
    }
}
