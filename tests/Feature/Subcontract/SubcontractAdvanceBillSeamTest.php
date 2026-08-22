<?php

namespace Tests\Feature\Subcontract;

use LogicException;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\ProjectCost;
use Modules\Finance\Models\Tax;
use Modules\Finance\Services\ApBillService;
use Modules\Subcontract\Services\AdvanceService;
use ReflectionClass;
use RuntimeException;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * The FINANCE half of uang muka subkon (temuan #49): the opname bill that
 * consumes the DP.
 *
 * The Subcontract module computes advance_recovery_amount on the claim; the
 * bill built from that claim must be priced NET of it (so the vendor is paid
 * the claim's net_payable, not the full gross) while the journal still debits
 * the FULL gross to subcon cost and credits the recovered slice back out of
 * 1-1500 — the exact netting a PO final bill does to its uang muka. That
 * behaviour lives in ApBillService, which this lane does not own; it is
 * delivered as seam patches the orchestrator applies.
 *
 * SELF-ARMING: until the seams land in ApBillService, every test here skips —
 * loudly, naming the seam — instead of failing a suite for code that has not
 * been applied yet. The moment the patched file contains
 * subconAdvanceRecovery(), the skips disappear and the journal is pinned.
 */
class SubcontractAdvanceBillSeamTest extends ErpTestCase
{
    use SubcontractFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $path = (new ReflectionClass(ApBillService::class))->getFileName();

        if (! str_contains((string) file_get_contents($path), 'subconAdvanceRecovery')) {
            $this->markTestSkipped(
                'Seam ApBillService (potongan uang muka subkon) belum diterapkan — lihat seams lane subcon.'
            );
        }

        $this->seedLedger(2026);

        Tax::create([
            'code' => Tax::pphFinalCodeForScheme('pelaksanaan_bersertifikat'),
            'name' => 'PPh Final Konstruksi 2,65%',
            'rate' => 2.65,
            'tax_type' => 'pph_withholding',
            'coa_account_id' => (int) Account::query()->where('code', '2-1230')->value('id'),
        ]);
    }

    private function balanceOf(string $accountCode): float
    {
        $accountId = Account::query()->where('code', $accountCode)->value('id');

        if ($accountId === null) {
            throw new RuntimeException("COA account {$accountCode} is missing; call seedLedger() first.");
        }

        $row = JournalLine::query()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->where('fin_journal_lines.account_id', (int) $accountId)
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit),0) as d, COALESCE(SUM(fin_journal_lines.credit),0) as c')
            ->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    /**
     * SPK 200 juta (retensi 5%, PPN 11%, PPh 2,65%), DP 40 juta dicairkan,
     * opname 50% (potongan DP 20 juta) — the numbers SubcontractAdvanceTest
     * already pins on the claim, followed here into the bill and the ledger.
     */
    public function test_the_opname_bill_nets_the_recovery_and_the_journal_consumes_the_advance(): void
    {
        $spk = $this->makeApprovedSubcontract([
            'value' => 200_000_000.0,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'pph_rate' => 2.65,
        ]);
        $line = $this->addLine($spk, [
            'description' => 'Pekerjaan struktur beton',
            'qty' => 1,
            'unit_price' => 200_000_000.0,
            'amount' => 200_000_000.0,
        ]);

        $advances = app(AdvanceService::class);
        $dp = $advances->createClaim($spk, ['amount' => 40_000_000, 'claim_date' => '2026-02-10']);
        $dp->submit($this->actor());
        $this->claimService()->approve($dp->refresh(), $this->approver());
        $advances->payout($spk, ['payout_date' => '2026-02-15'], $this->approver());

        $claim = $this->approvedClaim($spk->refresh(), [$line->id => 50]);

        $bill = app(ApBillService::class)->createFromSubconClaim($claim, ['bill_date' => '2026-04-05']);

        // Priced net, like a PO final bill net of its uang muka.
        $this->assertEqualsWithDelta(80_000_000, (float) $bill->dpp, 0.01);
        $this->assertEqualsWithDelta(81_150_000, (float) $bill->total_payable, 0.01,
            'the vendor is owed the claim net_payable: 95 + 8,8 − 2,65 − 20 juta');

        $bill->submit($this->actor());
        app(ApBillService::class)->approve($bill, $this->approver());

        $bill->refresh();
        $this->assertEqualsWithDelta(20_000_000, (float) $bill->advance_applied_amount, 0.01);

        // The DP asset: 40 juta paid out, 20 juta consumed by this opname.
        $this->assertEqualsWithDelta(20_000_000, $this->balanceOf('1-1500'), 0.01);
        // Cost carries the FULL work — the netting is a payment matter.
        $this->assertEqualsWithDelta(100_000_000, $this->balanceOf('5-1300'), 0.01);
        $this->assertEqualsWithDelta(100_000_000, (float) ProjectCost::query()
            ->where('project_id', $spk->project_id)->sum('amount'), 0.01);
        // Retention and PPh untouched by the netting.
        $this->assertEqualsWithDelta(-5_000_000, $this->balanceOf('2-1500'), 0.01);
        $this->assertEqualsWithDelta(-2_650_000, $this->balanceOf('2-1230'), 0.01);
    }

    public function test_a_dp_claim_is_refused_on_the_cost_billing_path(): void
    {
        $spk = $this->makeApprovedSubcontract([
            'value' => 200_000_000.0,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'pph_rate' => 2.65,
        ]);
        $this->addLine($spk, [
            'description' => 'Pekerjaan struktur beton',
            'qty' => 1,
            'unit_price' => 200_000_000.0,
            'amount' => 200_000_000.0,
        ]);

        $advances = app(AdvanceService::class);
        $dp = $advances->createClaim($spk, ['amount' => 40_000_000, 'claim_date' => '2026-02-10']);
        $dp->submit($this->actor());
        $this->claimService()->approve($dp->refresh(), $this->approver());

        // NOT paid out yet — before the seam, this path would happily build a
        // COST bill for the DP: the double-booking the finding measures.
        try {
            app(ApBillService::class)->createFromSubconClaim($dp->refresh(), ['bill_date' => '2026-02-20']);
            $this->fail('a DP claim pays out through the SPK screen, never the cost path');
        } catch (LogicException $e) {
            $this->assertStringContainsString('klaim uang muka', $e->getMessage());
        }

        $this->assertSame(0, ApBill::query()->count());
    }
}
