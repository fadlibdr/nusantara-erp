<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Finance\Enums\PettyCashVoucherStatus;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\PettyCashVoucher;
use Modules\Finance\Models\ProjectCost;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Voucher kas kecil (PCV): custodian-only posting fenced by ceiling and drawer
 * balance, and — the point of the package — a project bon that lands in
 * fin_project_costs (the PSAK 115 cost base) the day it is posted, not at
 * month-end.
 */
class PettyCashVoucherTest extends ErpTestCase
{
    use FinanceFixtures;
    use PettyCashFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
    }

    // -------------------------------------------------------- journal shapes

    public function test_a_project_fuel_voucher_books_hpp_and_lands_in_project_cost_the_same_day(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);
        $project = $this->makeProject();

        // Rp 150.000 of solar against the project, pinned to WBS task 7.
        $voucher = $this->makeVoucher($fund, [
            'category' => 'bbm_tol',
            'description' => 'Solar genset site Graha Sentosa',
            'amount' => 150000,
            'project_id' => $project->id,
            'wbs_task_id' => 7,
        ]);

        $this->vouchers()->post($voucher, $this->custodianUser());

        // Dr 5-1500 Beban Overhead Proyek (bbm_tol on a project) / Cr fund leaf.
        $journal = $this->singleJournalFor('petty_cash_voucher', (int) $voucher->id);
        $lines = $this->linesByAccount($journal);

        $this->assertSame(150000.0, $lines['5-1500']['debit']);
        $this->assertSame($project->id, $lines['5-1500']['project_id']);
        $this->assertSame(150000.0, $lines[$fund->coaAccount->code]['credit']);
        $this->assertPostedAndBalanced($journal, '2026-06-10');

        // The realisasi row exists the day the bon posts — dated on the bon,
        // categorised overhead, carrying the WBS pin. This is what keeps the
        // cost-to-cost percentage current without waiting for a manual JV.
        $cost = ProjectCost::query()
            ->where('reference_type', 'petty_cash_voucher')
            ->where('reference_id', $voucher->id)
            ->firstOrFail();

        $this->assertSame($project->id, (int) $cost->project_id);
        $this->assertSame('overhead', $cost->cost_category->value);
        $this->assertSame('2026-06-10', $cost->cost_date->toDateString());
        $this->assertSame(150000.0, (float) $cost->amount);
        $this->assertSame(7, (int) $cost->wbs_task_id);

        // And the drawer shrank by exactly the bon.
        $this->assertSame(4850000.0, $this->funds()->balance($fund->refresh()));
    }

    public function test_an_office_voucher_books_opex_and_writes_no_project_cost_row(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);

        $voucher = $this->makeVoucher($fund, [
            'category' => 'konsumsi',
            'description' => 'Nasi kotak rapat vendor',
            'amount' => 320000,
            'project_id' => null,
        ]);

        $this->vouchers()->post($voucher, $this->custodianUser());

        $lines = $this->linesByAccount($this->singleJournalFor('petty_cash_voucher', (int) $voucher->id));

        // Konsumsi without a project is 6-4100 Beban Umum & Administrasi.
        $this->assertSame(320000.0, $lines['6-4100']['debit']);
        $this->assertNull($lines['6-4100']['project_id']);

        $this->assertSame(0, ProjectCost::query()
            ->where('reference_type', 'petty_cash_voucher')
            ->where('reference_id', $voucher->id)
            ->count());
    }

    // ------------------------------------------------------- custodian guard

    public function test_posting_by_anyone_but_the_custodian_is_refused(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);
        $voucher = $this->makeVoucher($fund);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Hanya pemegang kas kecil/');

        // financeUser holds every service door open elsewhere — identity, not
        // permission, is what this guard checks.
        $this->vouchers()->post($voucher, $this->financeUser());
    }

    public function test_posting_by_the_custodian_works(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);
        $voucher = $this->makeVoucher($fund);

        $posted = $this->vouchers()->post($voucher, $this->custodianUser());

        $this->assertSame(PettyCashVoucherStatus::Posted, $posted->status);
        $this->assertNotNull($posted->posted_at);
    }

    // ----------------------------------------------------------------- fences

    public function test_a_voucher_over_the_per_bon_ceiling_is_refused_and_under_it_works(): void
    {
        $fund = $this->makeFund(['max_voucher_amount' => 500000]);
        $this->fundDrawer($fund, 5000000);

        $over = $this->makeVoucher($fund, ['amount' => 500001]);

        try {
            $this->vouchers()->post($over, $this->custodianUser());
            $this->fail('Expected the ceiling to refuse.');
        } catch (LogicException $e) {
            // The refusal points big spends at the AP-bill path.
            $this->assertStringContainsString('batas per bon', $e->getMessage());
            $this->assertStringContainsString('tagihan vendor', $e->getMessage());
        }

        $under = $this->makeVoucher($fund, ['amount' => 500000]);
        $this->assertSame(
            PettyCashVoucherStatus::Posted,
            $this->vouchers()->post($under, $this->custodianUser())->status,
        );
    }

    public function test_a_voucher_exceeding_the_unreplenished_drawer_is_refused_until_money_returns(): void
    {
        // Float Rp 5.000.000 but only Rp 1.000.000 ever funded: the paper
        // float does NOT spend — the drawer's posted balance does. This is the
        // overspent-unreplenished drawer, refused until it is topped back up.
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 1000000);

        $voucher = $this->makeVoucher($fund, ['amount' => 1500000, 'description' => 'Semen 30 sak']);

        try {
            $this->vouchers()->post($voucher, $this->custodianUser());
            $this->fail('Expected the drawer balance to refuse.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi saldo laci', $e->getMessage());
            $this->assertStringContainsString('Isi ulang dananya', $e->getMessage());
        }

        // Replenish the drawer (the ledger effect of a posted top-up PAY) and
        // the same bon posts.
        $this->fundDrawer($fund, 4000000, '2026-06-09');

        $this->assertSame(
            PettyCashVoucherStatus::Posted,
            $this->vouchers()->post($voucher->refresh(), $this->custodianUser())->status,
        );
    }

    public function test_a_voucher_dated_in_a_closed_period_is_refused(): void
    {
        $fund = $this->makeFund();
        // Funded in May, so the drawer HELD the cash on the bon's date — the
        // period guard, not the dated balance guard, must be what refuses.
        $this->fundDrawer($fund, 5000000, '2026-05-01');

        // The bon is back-dated into a closed May.
        FiscalPeriod::query()
            ->where('year', 2026)->where('month', 5)
            ->update(['status' => 'closed']);

        $voucher = $this->makeVoucher($fund, ['voucher_date' => '2026-05-20']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Periode fiskal 2026-05 sudah ditutup; jurnal tidak dapat diposting ke dalamnya.');

        $this->vouchers()->post($voucher, $this->custodianUser());
    }

    /**
     * Temuan #6: the drawer-balance guard read an UNDATED balance while the
     * journal carries the bon's own date — a bon back-dated 2026-05-20
     * against a drawer funded 2026-06-01 read 5.000.000, passed, and left
     * 1-11xx at −3.000.000 on the May balance sheet: negative cash, and a
     * May operating outflow of money that did not exist. May being open is
     * its normal state while it is being closed.
     */
    public function test_a_bon_back_dated_before_the_drawer_was_funded_is_refused(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        $backDated = $this->makeVoucher($fund, ['voucher_date' => '2026-05-20', 'amount' => 3000000]);

        try {
            $this->vouchers()->post($backDated, $this->custodianUser());
            $this->fail('A bon dated before the drawer held cash must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi saldo laci', $e->getMessage());
            // The refusal names the date whose balance said no.
            $this->assertStringContainsString('per 2026-05-20', $e->getMessage());
        }

        // Works-pair: dated the day the funding journal exists, the same
        // amount posts.
        $sameDay = $this->makeVoucher($fund, ['voucher_date' => '2026-06-01', 'amount' => 3000000]);

        $this->assertSame(
            PettyCashVoucherStatus::Posted,
            $this->vouchers()->post($sameDay, $this->custodianUser())->status,
        );
    }

    // ----------------------------------------------------------- cancellation

    public function test_cancelling_a_posted_voucher_reverses_the_journal_and_removes_the_cost_row(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);
        $project = $this->makeProject();

        $voucher = $this->makeVoucher($fund, ['category' => 'material', 'amount' => 400000, 'project_id' => $project->id]);
        $this->vouchers()->post($voucher, $this->custodianUser());

        $this->assertSame(4600000.0, $this->funds()->balance($fund));

        $cancelled = $this->vouchers()->cancel($voucher->refresh(), $this->financeApprover(), 'Bon ganda — sudah tercatat di PCV lain');

        $this->assertSame(PettyCashVoucherStatus::Cancelled, $cancelled->status);
        $this->assertSame('Bon ganda — sudah tercatat di PCV lain', $cancelled->cancellation_reason);

        // Mirror journal: cash back in the drawer, cost row gone — the project
        // P&L and the GL agree again.
        $this->assertSame(5000000.0, $this->funds()->balance($fund));
        $this->assertSame(0, ProjectCost::query()
            ->where('reference_type', 'petty_cash_voucher')
            ->where('reference_id', $voucher->id)
            ->count());
    }

    public function test_cancelling_a_voucher_already_reimbursed_by_a_posted_replenishment_is_refused(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);
        $bank = $this->makeBankAccount('1-1210');

        $voucher = $this->makeVoucher($fund, ['amount' => 900000]);
        $this->vouchers()->post($voucher, $this->custodianUser());

        // The replenishment PAY that covered this bon, walked through its full
        // chain: submit stamps the voucher, post moves the bank money.
        $payment = $this->payments()->create([
            'direction' => 'out',
            'payment_date' => '2026-06-20',
            'bank_account_id' => $bank->id,
            'amount' => 900000,
            'petty_cash_fund_id' => $fund->id,
        ]);
        $allocations = [['payable_type' => 'petty_cash_fund', 'payable_id' => $fund->id, 'amount' => 900000]];
        $this->payments()->post($this->approveOutgoingPayment($payment, $allocations), $allocations);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah diganti oleh isi ulang/');

        $this->vouchers()->cancel(
            PettyCashVoucher::query()->findOrFail($voucher->id),
            $this->financeApprover(),
            'Terlambat menyadari bon ganda',
        );
    }
}
