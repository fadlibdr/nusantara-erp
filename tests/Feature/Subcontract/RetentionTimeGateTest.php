<?php

namespace Tests\Feature\Subcontract;

use LogicException;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\Tax;
use Modules\Finance\Services\ApBillService;
use Modules\Subcontract\Models\Subcontract;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * Gate WAKTU pelepasan retensi — temuan #75.
 *
 * The guards release() always had are LEDGER guards: they answer "is this
 * money actually held". Nothing answered "may it be let go YET" — retention is
 * the jaminan cacat mutu for the masa pemeliharaan, and before this gate the
 * 5 % could be released the day after the first opname, with the whole
 * warranty period still ahead. The customer side has
 * prj_bast.retention_release_due; the subcon side had nothing.
 *
 * The gate compares the RELEASE DATE against scm_subcontracts
 * .defect_liability_until, refuses an SPK that never recorded one, and yields
 * only to an override WITH A REASON — which the release row then carries, so
 * the audit trail names why the guarantee was let go early.
 */
class RetentionTimeGateTest extends ErpTestCase
{
    use SubcontractFixtures;

    private const RETENTION = 5_000_000.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        // PPh final jasa konstruksi, so the opname bill withholds down the real
        // 2-1230 path instead of the untyped fallback.
        Tax::create([
            'code' => Tax::pphFinalCodeForScheme('pelaksanaan_bersertifikat'),
            'name' => 'PPh Final Konstruksi 2,65%',
            'rate' => 2.65,
            'tax_type' => 'pph_withholding',
            'coa_account_id' => (int) Account::query()->where('code', '2-1230')->value('id'),
        ]);
    }

    /**
     * An SPK with Rp 5.000.000 of retention genuinely credited to 2-1500, so
     * every refusal in this file is the TIME gate and never a ledger guard.
     */
    private function spkWithBilledRetention(?string $defectLiabilityUntil): Subcontract
    {
        $spk = $this->makeApprovedSubcontract([
            'value' => 200_000_000.0,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'pph_rate' => 2.65,
            'defect_liability_until' => $defectLiabilityUntil,
        ]);

        $line = $this->addLine($spk, [
            'description' => 'Pekerjaan struktur beton',
            'qty' => 1,
            'unit_price' => 200_000_000.0,
            'amount' => 200_000_000.0,
        ]);

        $claim = $this->approvedClaim($spk, [$line->id => 50]);

        $bill = app(ApBillService::class)->createFromSubconClaim($claim, ['bill_date' => '2026-04-05']);
        $bill->submit($this->actor());
        app(ApBillService::class)->approve($bill, $this->approver());

        return $spk;
    }

    private function release(Subcontract $spk, string $date, ?string $overrideReason = null)
    {
        return $this->retentionService()->release($spk, [
            'release_date' => $date,
            'amount' => self::RETENTION,
            'notes' => 'Pelepasan retensi',
            'override_reason' => $overrideReason,
        ], $this->actor());
    }

    public function test_a_release_before_the_defect_liability_end_is_refused(): void
    {
        $spk = $this->spkWithBilledRetention('2026-10-01');
        $billsBefore = ApBill::query()->count();

        try {
            $this->release($spk, '2026-05-10');
            $this->fail('sehari setelah opname pertama bukan akhir masa pemeliharaan');
        } catch (LogicException $e) {
            $this->assertStringContainsString('masa pemeliharaan berakhir (2026-10-01)', $e->getMessage());
        }

        // Refused means refused entirely: no bill, no release row, and the SPK
        // still reports the retention held.
        $this->assertSame($billsBefore, ApBill::query()->count());
        $this->assertSame(0, $spk->retentionReleases()->count());
        $this->assertEqualsWithDelta(
            self::RETENTION,
            $this->retentionService()->balance($spk)['balance'],
            0.01,
        );
    }

    public function test_on_or_after_the_date_no_override_is_needed(): void
    {
        $spk = $this->spkWithBilledRetention('2026-10-01');

        $release = $this->release($spk, '2026-10-01');

        $this->assertEqualsWithDelta(self::RETENTION, (float) $release->amount, 0.01);
        $this->assertNull($release->override_reason, 'nothing was overridden, so nothing is recorded');
    }

    public function test_an_early_release_with_a_reason_passes_and_the_reason_is_kept(): void
    {
        $spk = $this->spkWithBilledRetention('2026-10-01');

        $release = $this->release($spk, '2026-05-10', 'BAST II dipercepat sesuai berita acara 12/BA/2026');

        $this->assertSame(
            'BAST II dipercepat sesuai berita acara 12/BA/2026',
            $release->override_reason,
            'the WHY sits on the release row, next to the bill approvals naming WHO',
        );
    }

    /**
     * A missing date is not a missing gate. An SPK that never recorded its
     * masa pemeliharaan cannot prove the period ended — and every SPK from
     * before this column existed is exactly such an SPK, so an open door here
     * would exempt the entire existing portfolio.
     */
    public function test_an_spk_without_the_date_is_gated_too(): void
    {
        $spk = $this->spkWithBilledRetention(null);

        try {
            $this->release($spk, '2026-05-10');
            $this->fail('tanpa tanggal tercatat, gate tidak boleh hilang');
        } catch (LogicException $e) {
            $this->assertStringContainsString('belum mencatat akhir masa pemeliharaan', $e->getMessage());
        }

        $release = $this->release($spk, '2026-05-10', 'Masa pemeliharaan disepakati selesai — SPK lama tanpa tanggal');

        $this->assertEqualsWithDelta(self::RETENTION, (float) $release->amount, 0.01);
        $this->assertNotNull($release->override_reason);
    }
}
