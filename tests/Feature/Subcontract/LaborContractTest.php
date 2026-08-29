<?php

namespace Tests\Feature\Subcontract;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\Vendor;
use Modules\Subcontract\Models\LaborContract;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\LaborFixtures;

/**
 * P4 — SP3 Induk (SPK mandor upah borongan), deviasi 3.5.
 */
class LaborContractTest extends ErpTestCase
{
    use LaborFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->adminUser());
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'vendor_id' => $this->defaultMandor()->id,
            'project_id' => $this->defaultLaborProject()->id,
            'title' => 'Upah borongan pasangan bata tower A',
            'pph_scheme' => 'final_umkm',
            'start_date' => '2026-02-01',
            'items' => [
                ['description' => 'Pasangan bata merah', 'qty' => 200, 'unit' => 'm2', 'unit_rate' => 45000],
                ['description' => 'Plesteran + acian', 'qty' => 180, 'unit' => 'm2', 'unit_rate' => 35000],
            ],
        ], $overrides);
    }

    public function test_sp3_dibuat_dengan_nilai_dari_baris_dan_snapshot_pph_final_umkm(): void
    {
        $response = $this->postJson('/api/subcontract/labor-contracts', $this->validPayload())->assertCreated();

        // 200 x 45.000 + 180 x 35.000 = 9.000.000 + 6.300.000 = 15.300.000
        $this->assertSame('15300000.00', $response->json('data.value'));
        $this->assertSame('final_umkm', $response->json('data.pph_scheme'));
        // Snapshot tarif PP 55/2022 dari config (0,5%) — bukan tarif PP 9/2022.
        $this->assertSame('0.5000', $response->json('data.pph_rate'));
        // Mandor non-PKP: tanpa PPN.
        $this->assertSame('0.0000', $response->json('data.ppn_rate'));
        $this->assertStringStartsWith('SP3/', $response->json('data.code'));
    }

    public function test_sp3_untuk_vendor_bukan_mandor_ditolak_422(): void
    {
        $supplier = Vendor::create([
            'name' => 'PT Semen Distribusi Utama', 'classification' => 'material',
            'vendor_type' => 'supplier', 'status' => 'active',
        ]);
        $subkon = Vendor::create([
            'name' => 'CV Karya Sipil', 'classification' => 'sipil',
            'vendor_type' => 'subcontractor', 'status' => 'active',
        ]);

        foreach ([$supplier, $subkon] as $vendor) {
            $response = $this->postJson(
                '/api/subcontract/labor-contracts',
                $this->validPayload(['vendor_id' => $vendor->id]),
            )->assertUnprocessable();

            $this->assertStringContainsString('bukan vendor bertipe mandor', (string) $response->json('message'));
        }

        $this->assertSame(0, LaborContract::query()->count());
    }

    public function test_skema_pph21_ter_adalah_pintu_jujur_yang_belum_dibangun(): void
    {
        $response = $this->postJson(
            '/api/subcontract/labor-contracts',
            $this->validPayload(['pph_scheme' => 'pph21_ter']),
        )->assertUnprocessable();

        $message = (string) $response->json('message');
        $this->assertStringContainsString('belum diaktifkan', $message);
        $this->assertStringContainsString('PPh final UMKM', $message);
        $this->assertSame(0, LaborContract::query()->count());

        // Skema yang benar-benar tak dikenal tetap ditolak VALIDATOR (bentuk),
        // bukan service — dua pintu yang berbeda alasan penolakannya.
        $this->postJson(
            '/api/subcontract/labor-contracts',
            $this->validPayload(['pph_scheme' => 'flat_5persen']),
        )->assertUnprocessable()->assertJsonValidationErrors('pph_scheme');
    }

    public function test_gate_k3l_pakta_berlaku_untuk_mandor_saat_submit(): void
    {
        $blocked = $this->makeMandor(['k3l_documents' => false, 'name' => 'Mandor Tanpa K3L']);

        $contract = $this->makeLaborContract(['vendor_id' => $blocked->id], [[]]);

        $response = $this->postJson("/api/subcontract/labor-contracts/{$contract->id}/submit")
            ->assertUnprocessable();

        $this->assertStringContainsString('komitmen K3L', (string) $response->json('message'));
        $this->assertSame(DocumentStatus::Draft, $contract->fresh()->status);

        // Override beralasan tetap jalan daruratnya, dan alasannya tercatat.
        $this->postJson("/api/subcontract/labor-contracts/{$contract->id}/submit", [
            'qualification_override_reason' => 'Mobilisasi besok pagi; K3L ditandatangani di site.',
        ])->assertOk();

        $fresh = $contract->fresh();
        $this->assertSame(DocumentStatus::Submitted, $fresh->status);
        $this->assertSame(
            'Mobilisasi besok pagi; K3L ditandatangani di site.',
            $fresh->qualification_override_reason,
        );
    }

    public function test_maker_checker_pengaju_tidak_boleh_menyetujui_sp3_sendiri(): void
    {
        $contract = $this->makeLaborContract([], [[]]);

        $contract->submit($this->laborActor());

        // Approve lewat SERVICE — dan maker-checker di dalam trait menolak
        // pengajunya sendiri.
        try {
            $this->laborContracts()->approve($contract->refresh(), $this->laborActor());
            $this->fail('Pengaju tidak boleh menyetujui SP3-nya sendiri.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('submitted', $contract->fresh()->status->value);
        }

        $approved = $this->laborContracts()->approve($contract->refresh(), $this->laborApprover());
        $this->assertSame(DocumentStatus::Approved, $approved->status);
    }

    public function test_sp3_yang_disetujui_tidak_dapat_diubah_dan_yang_beropname_tidak_terhapus(): void
    {
        $contract = $this->makeApprovedLaborContract([], [['qty' => 100, 'unit_rate' => 50000, 'amount' => 5000000]]);
        $item = $contract->items()->first();

        $this->putJson("/api/subcontract/labor-contracts/{$contract->id}", ['title' => 'Diubah'])
            ->assertUnprocessable();

        $this->approvedLaborClaim($contract, [$item->id => 40]);

        // Approved menolak lebih dulu atas dasar status (pola SPK)...
        $response = $this->deleteJson("/api/subcontract/labor-contracts/{$contract->id}")
            ->assertUnprocessable();
        $this->assertStringContainsString('tidak dapat diubah', (string) $response->json('message'));
        $this->assertNotNull(LaborContract::query()->find($contract->id));

        // ...dan guard opname berdiri sendiri: SP3 yang KEMBALI editable
        // (ditolak approver) tetapi punya jejak opname tetap tak terhapus —
        // riwayat volume approved tidak boleh kehilangan induknya.
        $contract->forceFill(['status' => DocumentStatus::Rejected])->save();

        try {
            $this->laborContracts()->delete($contract->refresh());
            $this->fail('SP3 dengan opname tidak boleh terhapus.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('opname mandor', $e->getMessage());
        }

        $this->assertNotNull(LaborContract::query()->find($contract->id));
    }
}
