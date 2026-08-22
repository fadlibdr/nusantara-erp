<?php

namespace Tests\Feature\Subcontract;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\Vendor;
use Modules\Subcontract\Models\Subcontract;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * Gate prakualifikasi di sisi SPK — temuan #35, separuh yang tertinggal.
 *
 * SubcontractService::create sudah menolak vendor bermasalah, tapi submit()
 * TIDAK memeriksa apa pun: SBU bisa kedaluwarsa — dan subkon bisa
 * dinonaktifkan — di antara draf dan pengajuan, dan update() bebas menukar
 * vendor_id sebuah draf. Draf yang di-repoint ke subkon terblokir lalu
 * diajukan menjadi komitmen Rp miliaran tanpa satu pun pemeriksaan.
 *
 * Cermin sisi PO: gate berdiri di SubcontractController::submit, dengan
 * kontrak override-beralasan yang sama, dan alasannya dicap HANYA setelah
 * submit() benar-benar berhasil. Penukaran vendor pada draf sengaja tetap
 * bebas — satu-satunya jalan draf menjadi komitmen adalah submit, dan gate
 * ini berdiri persis di jalan itu.
 */
class SubcontractQualificationGateTest extends ErpTestCase
{
    use SubcontractFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->adminUser());
    }

    private function blockedSubcontractor(): Vendor
    {
        return $this->makeVendor([
            'name' => 'CV Subkon Bermasalah',
            'status' => 'inactive',
        ]);
    }

    private function submitVia(Subcontract $spk, array $payload = [])
    {
        return $this->postJson("/api/subcontract/subcontracts/{$spk->id}/submit", $payload);
    }

    public function test_submit_spk_vendor_bermasalah_ditolak_422(): void
    {
        $spk = $this->makeSubcontract(['vendor_id' => $this->blockedSubcontractor()->id]);

        $response = $this->submitVia($spk)->assertUnprocessable();
        $this->assertStringContainsString('nonaktif', (string) $response->json('message'));

        $this->assertSame(DocumentStatus::Draft, $spk->fresh()->status);
    }

    public function test_submit_spk_dengan_override_lolos_dan_alasannya_tercatat(): void
    {
        $spk = $this->makeSubcontract(['vendor_id' => $this->blockedSubcontractor()->id]);

        $this->submitVia($spk, [
            'qualification_override_reason' => 'Subkon tunggal yang menguasai metode kerja eksisting',
        ])->assertOk();

        $fresh = $spk->fresh();
        $this->assertSame(DocumentStatus::Submitted, $fresh->status);
        $this->assertSame(
            'Subkon tunggal yang menguasai metode kerja eksisting',
            $fresh->qualification_override_reason,
        );
    }

    public function test_submit_spk_vendor_sehat_tidak_terganggu_dan_alasan_nyasar_diabaikan(): void
    {
        $spk = $this->makeSubcontract(); // defaultVendor(): subkon aktif

        $this->submitVia($spk, [
            'qualification_override_reason' => 'Salah paham formulir — subkon ini sehat',
        ])->assertOk();

        $fresh = $spk->fresh();
        $this->assertSame(DocumentStatus::Submitted, $fresh->status);
        $this->assertNull(
            $fresh->qualification_override_reason,
            'Alasan yang diketik untuk subkon sehat bukan jejak override.',
        );
    }

    /**
     * Lubang yang membuat gate di create() saja tidak cukup: draf lahir untuk
     * subkon sehat, lalu update() menukar vendor_id ke subkon terblokir.
     * Penukarannya sendiri boleh (draf masih bisa diedit); yang harus
     * tertangkap adalah SUBMIT sesudahnya.
     */
    public function test_vendor_swap_pada_draf_tertangkap_gate_saat_submit(): void
    {
        $spk = $this->makeSubcontract(); // lahir untuk subkon sehat
        $blocked = $this->blockedSubcontractor();

        $this->putJson("/api/subcontract/subcontracts/{$spk->id}", [
            'vendor_id' => $blocked->id,
        ])->assertOk();

        $response = $this->submitVia($spk)->assertUnprocessable();
        $this->assertStringContainsString('nonaktif', (string) $response->json('message'));

        $fresh = $spk->fresh();
        $this->assertSame(DocumentStatus::Draft, $fresh->status);
        $this->assertSame($blocked->id, (int) $fresh->vendor_id);
    }

    /**
     * Cermin lubang 1 sisi PO: submit yang ditolak karena STATUS tidak boleh
     * meninggalkan jejak override.
     */
    public function test_submit_yang_ditolak_karena_status_tidak_mencap_alasan_override(): void
    {
        $spk = $this->makeSubcontract([
            'vendor_id' => $this->blockedSubcontractor()->id,
            'status' => DocumentStatus::Submitted,
        ]);

        $response = $this->submitVia($spk, [
            'qualification_override_reason' => 'Subkon tunggal yang menguasai metode kerja eksisting',
        ])->assertUnprocessable();

        $this->assertStringContainsString('Cannot submit', (string) $response->json('message'));
        $this->assertNull($spk->fresh()->qualification_override_reason);
    }

    public function test_submit_spk_vendor_terhapus_ditolak_dengan_pesan(): void
    {
        $spk = $this->makeSubcontract();
        $spk->vendor->delete(); // soft delete: relasi jadi null

        $response = $this->submitVia($spk->fresh())->assertUnprocessable();
        $this->assertStringContainsString('dihapus', (string) $response->json('message'));

        $this->assertSame(DocumentStatus::Draft, $spk->fresh()->status);
    }

    /**
     * Cermin PoService::create: SPK yang LAHIR lewat override kini menyimpan
     * alasannya di dokumen (dulu di-pull lalu dibuang karena kolomnya belum
     * ada) — dan vendor sehat tetap NULL.
     */
    public function test_create_spk_dengan_override_menyimpan_alasannya(): void
    {
        $spk = $this->subcontractService()->create([
            'vendor_id' => $this->blockedSubcontractor()->id,
            'project_id' => $this->defaultProject()->id,
            'title' => 'Pekerjaan bekisting',
            'pph_scheme' => 'pelaksanaan_bersertifikat',
            'start_date' => '2026-08-10',
            'qualification_override_reason' => 'Perpanjangan SBU sedang diproses LPJK',
            'items' => [
                ['description' => 'Bekisting kolom', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 50_000_000],
            ],
        ]);

        $this->assertSame(
            'Perpanjangan SBU sedang diproses LPJK',
            $spk->fresh()->qualification_override_reason,
        );
    }

    public function test_create_spk_vendor_sehat_mengabaikan_alasan_yang_terlanjur_diketik(): void
    {
        $spk = $this->subcontractService()->create([
            'vendor_id' => $this->defaultVendor()->id,
            'project_id' => $this->defaultProject()->id,
            'title' => 'Pekerjaan bekisting',
            'pph_scheme' => 'pelaksanaan_bersertifikat',
            'start_date' => '2026-08-10',
            'qualification_override_reason' => 'Salah paham formulir — subkon ini sehat',
            'items' => [
                ['description' => 'Bekisting kolom', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 50_000_000],
            ],
        ]);

        $this->assertNull($spk->fresh()->qualification_override_reason);
    }
}
