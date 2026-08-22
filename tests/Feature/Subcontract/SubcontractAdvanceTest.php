<?php

namespace Tests\Feature\Subcontract;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\ProjectCost;
use Modules\Finance\Services\ApBillService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Models\Subcontract;
use Modules\Subcontract\Models\SubcontractItem;
use Modules\Subcontract\Services\AdvanceService;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * Uang muka subkon — temuan #49.
 *
 * DP mobilisasi 10–20 % is standard on a construction SPK and had no path at
 * all: ApBillService refuses non-PO advances, and forcing the DP through a
 * manual bill debits project cost — so the subcon cost is booked TWICE by the
 * time the opnames are billed.
 *
 * Angka yang dipakai di seluruh berkas ini:
 *
 *   SPK 200.000.000, retensi 5%, PPN 11%, PPh final 2,65%
 *   uang muka 20%       => DP 40.000.000, PPN 4.400.000, dibayar 44.400.000
 *       jurnal pencairan   Dr 1-1500 40jt / Dr 1-1600 4,4jt / Cr 2-1100 44,4jt
 *       — ASET dibayar dimuka, TANPA beban proyek dan tanpa PPh (DP belum
 *       membeli pekerjaan; retensi dan PPh dipotong atas PEKERJAAN).
 *   opname 50%          => bruto 100jt, retensi 5jt, PPh 2,65jt
 *       potongan DP proporsional 100jt × 40/200 = 20jt
 *       PPN (100 − 20)jt × 11% = 8,8jt   (PPN slice DP sudah difakturkan
 *       saat DP — persis netting uang muka PO di tagihan finalnya)
 *       netto = 95 + 8,8 − 2,65 − 20 = 81,15jt
 */
class SubcontractAdvanceTest extends ErpTestCase
{
    use SubcontractFixtures;

    private const SPK_VALUE = 200_000_000.0;

    private const DP = 40_000_000.0;

    private AdvanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->service = app(AdvanceService::class);
    }

    // ------------------------------------------------------------- fixtures

    /** @return array{0: Subcontract, 1: SubcontractItem} */
    private function approvedSpk(): array
    {
        $spk = $this->makeApprovedSubcontract([
            'value' => self::SPK_VALUE,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'pph_rate' => 2.65,
        ]);

        $line = $this->addLine($spk, [
            'description' => 'Pekerjaan struktur beton',
            'qty' => 1,
            'unit_price' => self::SPK_VALUE,
            'amount' => self::SPK_VALUE,
        ]);

        return [$spk, $line];
    }

    private function approvedAdvanceClaim(Subcontract $spk, float $amount = self::DP): ProgressClaim
    {
        $claim = $this->service->createClaim($spk, [
            'amount' => $amount,
            'claim_date' => '2026-02-10',
            'notes' => 'DP mobilisasi 20%',
        ]);

        // The DP walks the claims' own SoD flow: submitted by the maker,
        // approved by a second person, through the same Approvable pair.
        $claim->submit($this->actor());

        return $this->claimService()->approve($claim->refresh(), $this->approver());
    }

    private function paidOutAdvance(Subcontract $spk, float $amount = self::DP): ApBill
    {
        $this->approvedAdvanceClaim($spk, $amount);

        return $this->service->payout($spk, ['payout_date' => '2026-02-15'], $this->approver());
    }

    private function accountId(string $code): int
    {
        $id = Account::query()->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("COA account {$code} is missing; call seedLedger() first.");
        }

        return (int) $id;
    }

    /** Debit − credit over POSTED journals: negative means a credit balance. */
    private function balanceOf(string $accountCode): float
    {
        $row = JournalLine::query()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->where('fin_journal_lines.account_id', $this->accountId($accountCode))
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit),0) as d, COALESCE(SUM(fin_journal_lines.credit),0) as c')
            ->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    // -------------------------------------------------------- the DP claim

    public function test_the_advance_claim_carries_dp_math_no_retention_no_pph(): void
    {
        [$spk] = $this->approvedSpk();

        $claim = $this->approvedAdvanceClaim($spk);

        $this->assertTrue((bool) $claim->is_advance);
        $this->assertEqualsWithDelta(self::DP, (float) $claim->gross_amount, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $claim->retention_amount, 0.01, 'retensi dipotong atas pekerjaan, bukan DP');
        $this->assertEqualsWithDelta(0.0, (float) $claim->pph_amount, 0.01, 'PPh final dipotong atas pekerjaan, bukan DP');
        $this->assertEqualsWithDelta(4_400_000, (float) $claim->ppn_amount, 0.01);
        $this->assertEqualsWithDelta(44_400_000, (float) $claim->net_payable, 0.01);
    }

    public function test_a_second_live_advance_claim_is_refused(): void
    {
        [$spk] = $this->approvedSpk();
        $this->approvedAdvanceClaim($spk);

        try {
            $this->service->createClaim($spk, ['amount' => 10_000_000]);
            $this->fail('at most one live DP per SPK, mirroring one advance per PO');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah memiliki klaim uang muka', $e->getMessage());
        }
    }

    public function test_a_dp_larger_than_the_unclaimed_value_is_refused(): void
    {
        [$spk, $line] = $this->approvedSpk();
        $this->approvedClaim($spk, [$line->id => 90]); // 180 juta claimed, 20 juta left

        try {
            $this->service->createClaim($spk, ['amount' => self::DP]);
            $this->fail('a DP no future opname can recover must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi sisa nilai SPK yang belum diopname', $e->getMessage());
        }
    }

    /**
     * The plafon exclusion. Counting the DP as claimed work would eat plafon
     * twice: a 20 % DP would cap the opnames at 80 % and the SPK could never
     * be claimed out.
     */
    public function test_work_still_reaches_100_percent_with_an_approved_advance(): void
    {
        [$spk, $line] = $this->approvedSpk();
        $this->paidOutAdvance($spk);

        $claim = $this->approvedClaim($spk->refresh(), [$line->id => 100]);

        $this->assertSame(DocumentStatus::Approved, $claim->status);
        $this->assertEqualsWithDelta(self::SPK_VALUE, (float) $claim->gross_amount, 0.01);
    }

    // ----------------------------------------------------------- the payout

    public function test_the_payout_journal_is_a_prepaid_asset_not_a_cost(): void
    {
        [$spk] = $this->approvedSpk();
        $bill = $this->paidOutAdvance($spk);

        $this->assertSame(DocumentStatus::Approved, $bill->status);
        $this->assertTrue($bill->isAdvance());
        $this->assertEqualsWithDelta(44_400_000, (float) $bill->total_payable, 0.01);

        $journal = Journal::query()
            ->where('reference_type', 'ap_bill')
            ->where('reference_id', $bill->id)
            ->sole();

        $this->assertSame(PostingStatus::Posted, $journal->status);
        $this->assertSame('2026-02-15', $journal->journal_date->toDateString());

        $this->assertEqualsWithDelta(self::DP, $this->balanceOf('1-1500'), 0.01, 'DP duduk sebagai aset dibayar dimuka');
        $this->assertEqualsWithDelta(4_400_000, $this->balanceOf('1-1600'), 0.01);
        $this->assertEqualsWithDelta(-44_400_000, $this->balanceOf('2-1100'), 0.01);

        // The double-cost the finding warns about: the DP must write NOTHING
        // into the project cost ledger — the opnames will, in full.
        $this->assertEqualsWithDelta(0.0, (float) ProjectCost::query()
            ->where('project_id', $spk->project_id)->sum('amount'), 0.01);
    }

    public function test_payout_needs_an_approved_claim_and_happens_once(): void
    {
        [$spk] = $this->approvedSpk();

        try {
            $this->service->payout($spk, ['payout_date' => '2026-02-15'], $this->approver());
            $this->fail('no approved DP claim, nothing to pay out');
        } catch (LogicException $e) {
            $this->assertStringContainsString('belum memiliki klaim uang muka yang disetujui', $e->getMessage());
        }

        $bill = $this->paidOutAdvance($spk);

        try {
            $this->service->payout($spk, ['payout_date' => '2026-02-16'], $this->approver());
            $this->fail('one DP, one payout');
        } catch (LogicException $e) {
            $this->assertStringContainsString("sudah dicairkan lewat tagihan {$bill->code}", $e->getMessage());
        }
    }

    public function test_payout_is_refused_when_opnames_ate_the_recovery_room(): void
    {
        [$spk, $line] = $this->approvedSpk();
        $this->approvedAdvanceClaim($spk);

        // Approved between the DP claim and its payout: the whole SPK.
        $this->approvedClaim($spk, [$line->id => 100]);

        try {
            $this->service->payout($spk, ['payout_date' => '2026-06-15'], $this->approver());
            $this->fail('a DP paid after the work is fully claimed can never be recovered');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi sisa nilai SPK yang belum diopname', $e->getMessage());
        }
    }

    /**
     * The claim key is what makes the two billing doors mutually exclusive:
     * ApBillService's own "a bill already exists for this opname" guard now
     * refuses the DP claim being billed a second time down the COST path —
     * the exact route the finding shows double-booking subcon cost.
     */
    public function test_the_dp_claim_cannot_be_billed_again_through_the_cost_path(): void
    {
        [$spk] = $this->approvedSpk();
        $this->paidOutAdvance($spk);

        $claim = $spk->claims()->where('is_advance', true)->sole();

        $billsBefore = ApBill::query()->count();

        try {
            app(ApBillService::class)->createFromSubconClaim($claim, ['bill_date' => '2026-02-20']);
            $this->fail('the payout bill already carries this claim');
        } catch (LogicException $e) {
            // Which refusal fires depends on whether the ApBillService seam is
            // applied: before it, the claim key trips "A bill already exists
            // for opname"; after it, the DP is refused by name before the key
            // is even consulted. Both are the same door staying shut, so the
            // assertion is on the SHUT, not the wording.
            $this->assertSame($billsBefore, ApBill::query()->count());
        }
    }

    // --------------------------------------------------------- the recovery

    public function test_an_opname_after_a_funded_dp_recovers_it_proportionally(): void
    {
        [$spk, $line] = $this->approvedSpk();
        $this->paidOutAdvance($spk);

        $claim = $this->approvedClaim($spk->refresh(), [$line->id => 50]);

        $this->assertEqualsWithDelta(100_000_000, (float) $claim->gross_amount, 0.01);
        $this->assertEqualsWithDelta(5_000_000, (float) $claim->retention_amount, 0.01);
        $this->assertEqualsWithDelta(2_650_000, (float) $claim->pph_amount, 0.01);
        $this->assertEqualsWithDelta(20_000_000, (float) $claim->advance_recovery_amount, 0.01,
            '20% DP => 20 sen per rupiah opname');
        $this->assertEqualsWithDelta(8_800_000, (float) $claim->ppn_amount, 0.01,
            'PPN atas (bruto − potongan DP): slice DP sudah difakturkan saat DP');
        $this->assertEqualsWithDelta(81_150_000, (float) $claim->net_payable, 0.01);
    }

    public function test_an_opname_without_a_paid_out_dp_deducts_nothing(): void
    {
        [$spk, $line] = $this->approvedSpk();
        // Claim approved, payout NEVER raised: 1-1500 was never debited, so
        // deducting would short-pay the subcontractor for money he never got.
        $this->approvedAdvanceClaim($spk);

        $claim = $this->approvedClaim($spk, [$line->id => 50]);

        $this->assertEqualsWithDelta(0.0, (float) $claim->advance_recovery_amount, 0.01);
        $this->assertEqualsWithDelta(11_000_000, (float) $claim->ppn_amount, 0.01, 'PPN back on the full gross');
        $this->assertEqualsWithDelta(103_350_000, (float) $claim->net_payable, 0.01);
    }

    /**
     * The catch-up: an opname approved BEFORE the payout deducted nothing, so
     * proportionality alone would strand half the DP for ever. The opname that
     * claims the SPK out takes the whole remainder.
     */
    public function test_the_closing_opname_takes_the_whole_remainder(): void
    {
        $spk = $this->makeApprovedSubcontract([
            'value' => self::SPK_VALUE,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'pph_rate' => 2.65,
        ]);
        $line1 = $this->addLine($spk, ['description' => 'Struktur', 'qty' => 1, 'unit_price' => 100_000_000, 'amount' => 100_000_000]);
        $line2 = $this->addLine($spk, ['description' => 'Arsitektur', 'qty' => 1, 'unit_price' => 100_000_000, 'amount' => 100_000_000]);

        // Opname 1 lands before the DP exists: deducts nothing.
        $first = $this->approvedClaim($spk, [$line1->id => 100]);
        $this->assertEqualsWithDelta(0.0, (float) $first->advance_recovery_amount, 0.01);

        $this->paidOutAdvance($spk->refresh()); // DP 40 juta ≤ 100 juta unclaimed

        // Opname 2 closes the SPK: proportional would be 20 juta, the
        // remainder is 40 juta — the catch-up takes it all.
        $second = $this->approvedClaim($spk->refresh(), [$line2->id => 100]);

        $this->assertEqualsWithDelta(40_000_000, (float) $second->advance_recovery_amount, 0.01);
        $this->assertEqualsWithDelta(6_600_000, (float) $second->ppn_amount, 0.01, '(100 − 40) juta × 11%');
        $this->assertEqualsWithDelta(58_950_000, (float) $second->net_payable, 0.01, '95 + 6,6 − 2,65 − 40');

        $this->assertEqualsWithDelta(0.0, $this->service->outstanding($spk->refresh()), 0.01, 'the DP is fully recovered');
    }

    public function test_a_cancelled_payout_bill_stops_the_recovery(): void
    {
        [$spk, $line] = $this->approvedSpk();
        $bill = $this->paidOutAdvance($spk);

        app(ApBillService::class)->cancel($bill, $this->approver(), 'DP batal — pencairan keliru');

        // The cancellation reversed the 1-1500 debit, so there is no asset
        // left for an opname to credit back out.
        $claim = $this->approvedClaim($spk->refresh(), [$line->id => 50]);

        $this->assertEqualsWithDelta(0.0, (float) $claim->advance_recovery_amount, 0.01);
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('1-1500'), 0.01);
    }

    // ------------------------------------------------------------ HTTP wiring

    public function test_the_advance_endpoints_are_wired_and_the_payout_is_double_gated(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$spk] = $this->approvedSpk();

        $maker = User::query()->create([
            'name' => 'Staf Subkon',
            'email' => 'staf-subkon@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $maker->givePermissionTo('scm.create', 'scm.update');
        Sanctum::actingAs($maker);

        $created = $this->postJson("/api/subcontract/subcontracts/{$spk->id}/advance-claim", [
            'amount' => self::DP,
            'claim_date' => '2026-02-10',
        ])->assertCreated()->json('data');

        $this->assertTrue($created['is_advance']);

        $this->postJson("/api/subcontract/progress-claims/{$created['id']}/submit")->assertOk();

        $checker = User::query()->create([
            'name' => 'Manajer Subkon',
            'email' => 'manajer-subkon@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $checker->givePermissionTo('scm.approve', 'scm.post');
        Sanctum::actingAs($checker);

        $this->postJson("/api/subcontract/progress-claims/{$created['id']}/approve")->assertOk();

        // scm.post alone may not mint an approved payable — the same reasoning
        // (and test) the retention release went through.
        $this->postJson("/api/subcontract/subcontracts/{$spk->id}/advance-payout", [
            'payout_date' => '2026-02-15',
        ])->assertForbidden();

        $this->assertSame(0, ApBill::query()->count(), 'refused at the door means refused entirely');

        $releaser = User::query()->create([
            'name' => 'Kabag Keuangan',
            'email' => 'kabag-keuangan@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $releaser->givePermissionTo('scm.post', 'fin.approve');
        Sanctum::actingAs($releaser);

        $payout = $this->postJson("/api/subcontract/subcontracts/{$spk->id}/advance-payout", [
            'payout_date' => '2026-02-15',
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(44_400_000, (float) $payout['total_payable'], 0.01);

        $panel = $this->getJson("/api/subcontract/subcontracts/{$spk->id}/advance")
            ->assertOk()->json('data');

        $this->assertTrue($panel['paid_out']);
        $this->assertEqualsWithDelta(self::DP, $panel['outstanding'], 0.01);
        $this->assertSame($payout['ap_bill_code'], $panel['bill_code']);
    }
}
