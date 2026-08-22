<?php

namespace Tests\Feature\Crm;

use LogicException;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Temuan #73 — dua pola retensi, satu kontrak.
 *
 * The system supports two legitimate retention patterns: (a) withheld per
 * invoice via withhold_retention (dpp x contract retention_pct), and (b) a
 * "Retensi 5%" termin inside a schedule that sums to 100%. Used TOGETHER on
 * one contract, the 1-1350 Piutang Retensi balance doubles (~5% of contract
 * value) and the customer is effectively billed 105% — a reconciliation that
 * can never come out.
 *
 * The is_retention flag on a termin says the contract uses pattern (b); from
 * then on pattern (a) is refused for that contract's termin invoices. Existing
 * rows all keep false (the column is forward-only, never backfilled), so live
 * contracts already billing per-invoice retention are untouched.
 */
class RetentionPatternGuardTest extends ErpTestCase
{
    use FinanceFixtures;

    private Customer $customer;

    private Contract $contract;

    private ContractTermin $progress;

    private ContractTermin $retensi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->customer = $this->makeCustomer();
        // Nilai kontrak 10.000.000.000, retensi 5%, PPN 11% — jadwal memuat
        // termin retensi eksplisit, pola kontrak demo 1 dan 2.
        $this->contract = $this->makeContract($this->customer, ['value' => 10000000000]);
        $this->progress = $this->makeTermin($this->contract, 1, 'Termin 1 — Progres 50%', 50, 0);
        $this->makeTermin($this->contract, 2, 'Termin 2 — Progres 95%', 45, 0);
        $this->retensi = $this->makeTermin($this->contract, 3, 'Retensi 5%', 5, 0, ['is_retention' => true]);
    }

    public function test_withholding_is_refused_when_the_schedule_carries_a_retention_termin(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tercatat dobel/');

        $this->arInvoices()->create([
            'termin_id' => $this->progress->id,
            'invoice_date' => '2026-03-10',
            'withhold_retention' => true,
        ]);
    }

    /** An explicit rupiah amount is the same double-retention, refused alike. */
    public function test_an_explicit_retention_amount_is_refused_too(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tercatat dobel/');

        $this->arInvoices()->create([
            'termin_id' => $this->progress->id,
            'invoice_date' => '2026-03-10',
            'retention_withheld' => 250000000,
        ]);
    }

    public function test_billing_without_withholding_still_works_on_such_a_contract(): void
    {
        $invoice = $this->arInvoices()->create([
            'termin_id' => $this->progress->id,
            'invoice_date' => '2026-03-10',
        ]);

        $this->assertSame(5000000000.0, (float) $invoice->dpp);
        $this->assertSame(0.0, (float) $invoice->retention_withheld);
    }

    /** Pattern (b) collects its retention BY billing the flagged termin. */
    public function test_the_retention_termin_itself_bills_like_any_other(): void
    {
        $invoice = $this->arInvoices()->create([
            'termin_id' => $this->retensi->id,
            'invoice_date' => '2026-12-10',
        ]);

        // 10.000.000.000 * 5 / 100 = 500.000.000 — the retention, as revenue
        // billing, with nothing withheld on top of it.
        $this->assertSame(500000000.0, (float) $invoice->dpp);
        $this->assertSame(0.0, (float) $invoice->retention_withheld);
    }

    /**
     * Pattern (a) alone is untouched: a contract whose schedule carries no
     * retention termin keeps withholding per invoice exactly as before.
     */
    /**
     * The create-path guard alone is a fence with a gate left open: the SPA's
     * retention field is an ordinary editable currency on a DRAFT invoice, so
     * an invoice created clean could be EDITED into double retention a second
     * later. The verifier reproduced exactly that.
     */
    public function test_a_draft_edit_cannot_sneak_retention_back_onto_a_flagged_contract(): void
    {
        $invoice = $this->arInvoices()->create([
            'termin_id' => $this->progress->id,
            'invoice_date' => '2026-03-10',
        ]);

        try {
            $this->arInvoices()->update($invoice, ['retention_withheld' => 40000000]);
            $this->fail('Expected the update path to refuse retention on a flagged contract.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tercatat dobel', $e->getMessage());
        }

        $this->assertSame(0.0, (float) $invoice->fresh()->retention_withheld);
    }

    /** createManual is the same vector: no termin, same contract, same 1-1350 doubling. */
    public function test_a_manual_invoice_on_a_flagged_contract_is_refused_retention_too(): void
    {
        try {
            $this->arInvoices()->create([
                'customer_id' => $this->customer->id,
                'contract_id' => $this->contract->id,
                'invoice_date' => '2026-03-10',
                'description' => 'Pekerjaan tambah di luar termin',
                'dpp' => 100000000,
                'retention_withheld' => 5000000,
            ]);
            $this->fail('Expected the manual path to refuse retention on a flagged contract.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tercatat dobel', $e->getMessage());
        }
    }

    /** A zero-retention edit on a flagged contract keeps working — the guard bites the rupiah, not the field. */
    public function test_a_zero_retention_edit_still_works_on_a_flagged_contract(): void
    {
        $invoice = $this->arInvoices()->create([
            'termin_id' => $this->progress->id,
            'invoice_date' => '2026-03-10',
        ]);

        $updated = $this->arInvoices()->update($invoice, [
            'description' => 'Deskripsi baru',
            'retention_withheld' => 0,
        ]);

        $this->assertSame('Deskripsi baru', $updated->description);
    }

    public function test_per_invoice_withholding_still_works_when_no_termin_is_flagged(): void
    {
        $contract = $this->makeContract($this->customer, ['value' => 2000000000]);
        $termin = $this->makeTermin($contract, 1, 'DP 20%', 20, 0);

        $invoice = $this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-03-10',
            'withhold_retention' => true,
        ]);

        // DPP 400.000.000 * retention_pct 5% = 20.000.000
        $this->assertSame(20000000.0, (float) $invoice->retention_withheld);
    }
}
