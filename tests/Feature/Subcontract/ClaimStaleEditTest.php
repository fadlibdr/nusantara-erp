<?php

namespace Tests\Feature\Subcontract;

use LogicException;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Services\AdvanceService;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * updateClaim memutuskan boleh-tidaknya edit pada INSTANCE yang bisa basi.
 *
 * Route model binding mengambil baris opname, lalu assertEditable membaca
 * status dari instance itu — di luar transaksi. Persetujuan yang mendarat di
 * antara pengambilan dan update tidak terlihat: instance masih berkata draft,
 * dan edit berjalan menimpa dokumen yang sudah disetujui.
 *
 * Sejak cabang uang muka, ini jalur UANG: updateClaim memanggil
 * recalcAdvance yang force-fill net_payable, dan payout mencetak tagihan AP
 * yang LANGSUNG approved dari angka itu — edit basi berarti mencairkan DP
 * dengan nilai yang tidak pernah melewati submit → approve siapa pun.
 */
class ClaimStaleEditTest extends ErpTestCase
{
    use SubcontractFixtures;

    /** delete() berbagi bentuk yang sama: keputusan editability harus jatuh pada baris yang dibaca ulang. */
    public function test_a_delete_after_a_concurrent_approval_is_refused(): void
    {
        $spk = $this->makeApprovedSubcontract([
            'value' => 100_000_000.0,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'pph_rate' => 2.65,
        ]);
        $line = $this->addLine($spk, [
            'qty' => 1,
            'unit_price' => 100_000_000.0,
            'amount' => 100_000_000.0,
        ]);

        $claim = $this->draftClaim($spk, [$line->id => 40]);

        /** @var ProgressClaim $stale */
        $stale = ProgressClaim::query()->findOrFail($claim->id);

        $claim->refresh()->submit($this->actor());
        $this->claimService()->approve($claim->refresh(), $this->approver());

        try {
            $this->claimService()->delete($stale);
            $this->fail('Hapus atas opname yang keburu disetujui harus ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('can no longer be edited', $e->getMessage());
        }

        $this->assertNotNull(ProgressClaim::query()->find($claim->id));
    }

    public function test_an_edit_after_a_concurrent_approval_is_refused(): void
    {
        $spk = $this->makeApprovedSubcontract([
            'value' => 100_000_000.0,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'pph_rate' => 2.65,
        ]);
        $line = $this->addLine($spk, [
            'qty' => 1,
            'unit_price' => 100_000_000.0,
            'amount' => 100_000_000.0,
        ]);

        $claim = $this->draftClaim($spk, [$line->id => 40]);

        // Instance milik controller — diambil SEBELUM persetujuan mendarat.
        /** @var ProgressClaim $stale */
        $stale = ProgressClaim::query()->findOrFail($claim->id);

        $claim->refresh()->submit($this->actor());
        $this->claimService()->approve($claim->refresh(), $this->approver());

        try {
            $this->claimService()->updateClaim($stale, ['notes' => 'diedit setelah disetujui']);
            $this->fail('Edit atas opname yang keburu disetujui harus ditolak, bukan menimpa dokumen approved.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('can no longer be edited', $e->getMessage());
        }

        $this->assertNotSame('diedit setelah disetujui', $claim->refresh()->notes);
    }

    /** Jalur uangnya: DP yang disetujui tidak boleh berubah nilai lewat edit basi. */
    public function test_a_stale_edit_cannot_reprice_an_approved_advance(): void
    {
        $spk = $this->makeApprovedSubcontract([
            'value' => 200_000_000.0,
            'ppn_rate' => 11.0,
        ]);

        $advance = app(AdvanceService::class)->createClaim($spk, ['amount' => 40_000_000.0]);

        /** @var ProgressClaim $stale */
        $stale = ProgressClaim::query()->findOrFail($advance->id);

        $advance->refresh()->submit($this->actor());
        $this->claimService()->approve($advance->refresh(), $this->approver());

        try {
            $this->claimService()->updateClaim($stale, ['amount' => 90_000_000.0]);
            $this->fail('DP yang sudah disetujui tidak boleh di-reprice oleh instance basi.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('can no longer be edited', $e->getMessage());
        }

        // Angka yang payout jadikan tagihan AP masih angka yang disetujui:
        // 40 jt + PPN 11% = 44,4 jt — bukan 99,9 jt hasil edit basi.
        $advance->refresh();
        $this->assertEqualsWithDelta(40_000_000.0, (float) $advance->gross_amount, 0.01);
        $this->assertEqualsWithDelta(44_400_000.0, (float) $advance->net_payable, 0.01);
    }
}
