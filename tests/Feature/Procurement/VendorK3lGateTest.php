<?php

namespace Tests\Feature\Procurement;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Services\FormPrintService;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Subcontract\Models\Subcontract;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * Gerbang K3L & pakta integritas untuk SUBKONTRAKTOR — paket P0-E.
 *
 * Filosofi gate lama sengaja tidak memblokir dokumen yang ABSEN ("register
 * yang belum diisi bukan pelanggaran" — gate yang memblokir semua vendor di
 * hari pertama langsung dimatikan orang). Paket ini MENYEMPITKANNYA secara
 * sadar, hanya untuk vendor bertanda is_subcontractor dan hanya untuk dua
 * jenis dokumen: komitmen K3L dan pakta integritas. Orang yang mengirim
 * pekerjanya ke site orang lain tanpa komitmen K3L bukan register yang belum
 * rapi — itulah risiko yang gerbangnya ada untuk menahan. Vendor material
 * murni tidak tersentuh.
 */
class VendorK3lGateTest extends ErpTestCase
{
    use SubcontractFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->adminUser());
    }

    private function subcontractor(array $attributes = []): Vendor
    {
        return $this->makeVendor(array_merge([
            'name' => 'CV Subkon Sipil Prima',
            'is_subcontractor' => true,
            'status' => 'active',
            // Uji gerbang ini justru menguji KETIADAAN dokumen.
            'k3l_documents' => false,
        ], $attributes));
    }

    private function giveDocument(Vendor $vendor, string $type, ?string $validUntil): void
    {
        $vendor->documents()->create([
            'doc_type' => $type,
            'name' => strtoupper(str_replace('_', ' ', $type)),
            'is_mandatory' => true,
            'valid_until' => $validUntil,
        ]);
    }

    private function submitVia(Subcontract $spk, array $payload = [])
    {
        return $this->postJson("/api/subcontract/subcontracts/{$spk->id}/submit", $payload);
    }

    public function test_subkon_tanpa_komitmen_k3l_ditolak_saat_submit_spk(): void
    {
        $vendor = $this->subcontractor();
        $this->giveDocument($vendor, 'pakta_integritas', '2030-12-31');

        $spk = $this->makeSubcontract(['vendor_id' => $vendor->id]);

        $response = $this->submitVia($spk)->assertUnprocessable();
        $this->assertStringContainsString('komitmen K3L', (string) $response->json('message'));
        $this->assertSame(DocumentStatus::Draft, $spk->fresh()->status);
    }

    public function test_subkon_tanpa_pakta_integritas_ditolak_dengan_nama_dokumennya(): void
    {
        $vendor = $this->subcontractor();
        $this->giveDocument($vendor, 'k3l_commitment', '2030-12-31');

        $spk = $this->makeSubcontract(['vendor_id' => $vendor->id]);

        $response = $this->submitVia($spk)->assertUnprocessable();
        $this->assertStringContainsString('pakta integritas', (string) $response->json('message'));
    }

    public function test_dokumen_kedaluwarsa_ditolak_menyebut_tanggalnya_dan_hari_terakhir_masih_sah(): void
    {
        $vendor = $this->subcontractor();
        $this->giveDocument($vendor, 'k3l_commitment', '2020-01-31');
        $this->giveDocument($vendor, 'pakta_integritas', now()->toDateString()); // hari terakhir

        $spk = $this->makeSubcontract(['vendor_id' => $vendor->id]);

        $response = $this->submitVia($spk)->assertUnprocessable();
        $message = (string) $response->json('message');
        $this->assertStringContainsString('31-01-2020', $message);
        // Pakta yang berlaku sampai HARI INI masih sah — aturan setengah-terbuka
        // yang sama dengan gate lama; hanya K3L yang disebut.
        $this->assertStringNotContainsString('pakta integritas', $message);
    }

    public function test_subkon_berdokumen_lengkap_lolos(): void
    {
        $vendor = $this->subcontractor();
        $this->giveDocument($vendor, 'k3l_commitment', '2030-12-31');
        $this->giveDocument($vendor, 'pakta_integritas', null); // tanpa masa berlaku = tidak kedaluwarsa

        $spk = $this->makeSubcontract(['vendor_id' => $vendor->id]);

        $this->submitVia($spk)->assertOk();
        $this->assertSame(DocumentStatus::Submitted, $spk->fresh()->status);
    }

    public function test_vendor_material_murni_tidak_tersentuh_gerbang_baru(): void
    {
        $vendor = $this->makeVendor([
            'name' => 'PT Material Saja',
            'is_subcontractor' => false,
            'status' => 'active',
            'k3l_documents' => false,
        ]);

        // PO path: create lolos tanpa K3L/pakta apa pun.
        $po = PurchaseOrder::query()->create([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-08-01',
            'status' => 'draft',
            'subtotal' => 1_000_000, 'dpp' => 1_000_000,
            'ppn_amount' => 110_000, 'total' => 1_110_000,
        ]);

        $this->postJson("/api/procurement/purchase-orders/{$po->id}/submit")->assertOk();
    }

    public function test_override_beralasan_tetap_meloloskan_dan_tercap(): void
    {
        $vendor = $this->subcontractor(); // tanpa dokumen sama sekali

        $spk = $this->makeSubcontract(['vendor_id' => $vendor->id]);

        $this->submitVia($spk, [
            'qualification_override_reason' => 'Mobilisasi darurat; komitmen K3L ditandatangani di site besok pagi',
        ])->assertOk();

        $this->assertSame(
            'Mobilisasi darurat; komitmen K3L ditandatangani di site besok pagi',
            $spk->fresh()->qualification_override_reason,
        );
    }

    /**
     * F/K3V tercetak dengan badan BERGARIS KOSONG — tanpa klausul karangan.
     *
     * Pemilik tidak menitipkan teks klausul K3L; lembar yang mengarang
     * klausul keselamatan akan dipercaya orang persis di saat yang salah.
     */
    public function test_f_k3v_tercetak_bergaris_tanpa_klausul_karangan(): void
    {
        $vendor = $this->subcontractor();

        $html = app(FormPrintService::class)
            ->html('persyaratan-k3l-vendor', ['id' => $vendor->id]);

        $this->assertStringContainsString('PERSYARATAN K3L UNTUK VENDOR', $html);
        $this->assertStringContainsString('Form F/K3V', $html);
        $this->assertStringContainsString('CV Subkon Sipil Prima', $html);
        $this->assertStringContainsString('Menyetujui dan menyanggupi,', $html);
        // Badan bergaris, bukan klausul: tidak ada kalimat kewajiban karangan.
        // 14 garis isian kosong dari 'lines' => 14 — blok Catatan merender
        // tiap barisnya sebagai <div class="rule"></div> (layout.blade.php).
        // Regex 'fill' versi pertama hanya menangkap fill-line kop yang ada
        // di SEMUA formulir, jadi uji ini dulu tak bisa gagal — temuan
        // verifikasi P0-E.
        $this->assertSame(14, substr_count($html, '<div class="rule"></div>'));
        foreach (['wajib menggunakan APD', 'dilarang', 'sanksi'] as $invented) {
            $this->assertStringNotContainsString($invented, $html);
        }
    }

    /**
     * Cabang kedaluwarsa klausul BARU teruji terpisah dari klausul lama.
     *
     * Baris non-wajib tidak pernah disentuh klausul lama, jadi hanya klausul
     * penyempitan yang bisa memblokirnya — menghapus cabang kedaluwarsanya
     * membuat uji ini merah (revert-proof yang ditagih verifikasi).
     */
    public function test_k3l_non_wajib_yang_kedaluwarsa_tetap_memblokir_subkon(): void
    {
        $vendor = $this->subcontractor();
        $vendor->documents()->create([
            'doc_type' => 'k3l_commitment', 'name' => 'Komitmen K3L',
            'is_mandatory' => false, 'valid_until' => '2020-06-30',
        ]);
        $this->giveDocument($vendor, 'pakta_integritas', null);

        $spk = $this->makeSubcontract(['vendor_id' => $vendor->id]);

        $response = $this->submitVia($spk)->assertUnprocessable();
        $this->assertStringContainsString('kedaluwarsa sejak 30-06-2020', (string) $response->json('message'));
    }

    /** Lembar K3L wajib-kedaluwarsa disebut SEKALI, bukan dua kali. */
    public function test_k3l_wajib_kedaluwarsa_disebut_sekali_dalam_pesan(): void
    {
        $vendor = $this->subcontractor();
        $this->giveDocument($vendor, 'k3l_commitment', '2020-06-30'); // is_mandatory=true
        $this->giveDocument($vendor, 'pakta_integritas', null);

        $spk = $this->makeSubcontract(['vendor_id' => $vendor->id]);

        $message = (string) $this->submitVia($spk)->assertUnprocessable()->json('message');
        $this->assertSame(1, substr_count($message, '30-06-2020'), $message);
    }

    /**
     * Subkon yang MEMPERBARUI K3L-nya lolos — baris basi yang dibiarkan
     * wajib tidak boleh menutupi baris segar (temuan verifikasi keempat).
     */
    public function test_pembaruan_k3l_dengan_baris_basi_tertinggal_tetap_lolos(): void
    {
        $vendor = $this->subcontractor();
        $this->giveDocument($vendor, 'k3l_commitment', '2020-06-30'); // basi, wajib
        $this->giveDocument($vendor, 'k3l_commitment', '2030-12-31'); // segar
        $this->giveDocument($vendor, 'pakta_integritas', null);

        $spk = $this->makeSubcontract(['vendor_id' => $vendor->id]);

        $this->submitVia($spk)->assertOk();
    }
}
