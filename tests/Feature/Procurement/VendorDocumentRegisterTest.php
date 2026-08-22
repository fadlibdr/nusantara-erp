<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\VendorDocument;
use Spatie\Permission\Models\Role;
use Tests\ErpTestCase;

/**
 * Register dokumen prakualifikasi vendor — temuan #35 dan #69 dalam SATU
 * register: SIUP/NIB/NPWP/SBU/SKK adalah jenis dokumen dengan masa berlaku,
 * bukan dua tabel terpisah.
 *
 * Sebelum register ini, prc_vendors hanya menyimpan NPWP/SPPKP sebagai teks
 * tanpa satu pun kolom tanggal kedaluwarsa — SBU subkon yang kadaluarsa baru
 * ketahuan saat owner proyek pemerintah meminta dokumennya.
 */
class VendorDocumentRegisterTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->adminUser());
    }

    private function vendor(array $attributes = []): Vendor
    {
        return Vendor::query()->create(array_merge([
            'name' => 'PT Subkon Struktur Utama',
            'classification' => 'sipil',
            'is_subcontractor' => true,
            'payment_term_days' => 30,
            'status' => 'active',
        ], $attributes));
    }

    public function test_daftar_buat_ubah_hapus_register_dokumen(): void
    {
        $vendor = $this->vendor();

        $created = $this->postJson('/api/procurement/vendor-documents', [
            'vendor_id' => $vendor->id,
            'doc_type' => 'sbu_konstruksi',
            'name' => 'SBU Konstruksi BG007',
            'number' => 'SBU-2024-0812',
            'issuer' => 'LPJK',
            'issued_date' => '2024-08-01',
            'valid_until' => '2027-08-01',
            'is_mandatory' => true,
        ])->assertCreated()->json('data');

        $this->assertSame('sbu_konstruksi', $created['doc_type']);
        $this->assertTrue($created['is_mandatory']);
        $this->assertFalse($created['is_expired']);

        $listed = $this->getJson("/api/procurement/vendor-documents?vendor_id={$vendor->id}")
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $listed);

        $this->putJson("/api/procurement/vendor-documents/{$created['id']}", [
            'valid_until' => '2028-08-01',
        ])->assertOk();
        $this->assertSame(
            '2028-08-01',
            substr((string) VendorDocument::query()->find($created['id'])->valid_until, 0, 10),
        );

        $this->deleteJson("/api/procurement/vendor-documents/{$created['id']}")->assertOk();
        $this->assertNull(VendorDocument::query()->find($created['id']));
    }

    public function test_kedaluwarsa_dihitung_lewat_hari_terakhir_dan_bisa_disaring(): void
    {
        $this->travelTo('2026-08-08');

        $vendor = $this->vendor();

        $expired = $this->document($vendor, ['name' => 'SIUP lama', 'doc_type' => 'siup', 'valid_until' => '2026-08-07']);
        // "Berlaku s/d" berarti masih sah PADA hari terakhirnya — register
        // jaminan dan deadline-watch memakai bacaan yang sama.
        $lastDay = $this->document($vendor, ['name' => 'NIB', 'doc_type' => 'nib', 'valid_until' => '2026-08-08']);
        $dateless = $this->document($vendor, ['name' => 'NPWP', 'doc_type' => 'npwp', 'valid_until' => null]);

        $rows = collect($this->getJson("/api/procurement/vendor-documents?vendor_id={$vendor->id}")
            ->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($rows[$expired->id]['is_expired']);
        $this->assertFalse($rows[$lastDay->id]['is_expired']);
        $this->assertFalse($rows[$dateless->id]['is_expired']);

        $expiredOnly = $this->getJson("/api/procurement/vendor-documents?vendor_id={$vendor->id}&expired=1")
            ->assertOk()->json('data');
        $this->assertSame([$expired->id], array_column($expiredOnly, 'id'));
    }

    public function test_register_dijaga_permission_prc(): void
    {
        $vendor = $this->vendor();

        $pengamat = Role::findOrCreate('pengamat-uji', 'web');
        $pengamat->syncPermissions(['prc.view']);

        $user = User::query()->create([
            'name' => 'Pengamat',
            'email' => 'pengamat@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($pengamat);
        Sanctum::actingAs($user);

        $this->postJson('/api/procurement/vendor-documents', [
            'vendor_id' => $vendor->id,
            'doc_type' => 'nib',
            'name' => 'NIB',
        ])->assertForbidden();
    }

    public function test_jenis_dokumen_di_luar_daftar_ditolak(): void
    {
        $vendor = $this->vendor();

        $this->postJson('/api/procurement/vendor-documents', [
            'vendor_id' => $vendor->id,
            'doc_type' => 'surat-cinta',
            'name' => 'Bukan dokumen legal',
        ])->assertUnprocessable();
    }

    private function document(Vendor $vendor, array $attributes = []): VendorDocument
    {
        return VendorDocument::query()->create(array_merge([
            'vendor_id' => $vendor->id,
            'doc_type' => 'nib',
            'name' => 'NIB 8120000000001',
            'is_mandatory' => false,
        ], $attributes));
    }
}
