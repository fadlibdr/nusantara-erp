<?php

namespace Tests\Feature\Procurement;

use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Rules\ValidNpwp;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\VendorDocument;
use Tests\ErpTestCase;

/**
 * P-3b T3b.1 — pintu tulis NPWP vendor (POST/PUT procurement/vendors).
 * Aturan yang sama dengan pelanggan, pegawai dan profil perusahaan.
 */
class VendorNpwpGateTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->adminUser());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'PT Vendor Baru', 'classification' => 'material', 'status' => 'active'], $overrides);
    }

    public function test_a_new_vendor_with_a_malformed_npwp_is_refused_with_the_sentence(): void
    {
        $this->postJson('/api/procurement/vendors', $this->payload(['npwp' => '01.334.556.7-007']))
            ->assertStatus(422)
            ->assertJsonPath('errors.npwp.0', ValidNpwp::MESSAGE);

        $this->assertSame(0, Vendor::query()->count());
    }

    public function test_the_three_accepted_forms_are_stored_as_typed(): void
    {
        foreach (['01.334.556.7-007.000', '0013345567007000', '0013345567007000000001'] as $i => $npwp) {
            $response = $this->postJson('/api/procurement/vendors', $this->payload(['name' => "PT Vendor {$i}", 'npwp' => $npwp]))
                ->assertCreated();

            $this->assertSame($npwp, Vendor::query()->find($response->json('data.id'))->npwp);
        }
    }

    public function test_updating_to_a_malformed_npwp_is_refused_and_the_row_is_untouched(): void
    {
        $vendor = Vendor::query()->create($this->payload(['npwp' => '01.334.556.7-007.000']));

        $this->putJson("/api/procurement/vendors/{$vendor->id}", ['npwp' => '12.345'])
            ->assertStatus(422)
            ->assertJsonPath('errors.npwp.0', ValidNpwp::MESSAGE);

        $this->assertSame('01.334.556.7-007.000', $vendor->fresh()->npwp);
    }

    public function test_a_legacy_row_is_read_as_is_and_may_be_edited_without_touching_its_npwp(): void
    {
        $vendor = Vendor::query()->create($this->payload(['name' => 'CV Warisan', 'npwp' => 'N/A']));

        $this->getJson("/api/procurement/vendors/{$vendor->id}")->assertOk()->assertJsonPath('data.npwp', 'N/A');

        $this->putJson("/api/procurement/vendors/{$vendor->id}", ['name' => 'CV Warisan', 'npwp' => 'N/A', 'city' => 'Bekasi'])
            ->assertOk();
        $this->assertSame('Bekasi', $vendor->fresh()->city);
        $this->assertSame('N/A', $vendor->fresh()->npwp);

        $this->putJson("/api/procurement/vendors/{$vendor->id}", ['name' => 'CV Warisan', 'npwp' => 'N/B'])
            ->assertStatus(422);

        $this->putJson("/api/procurement/vendors/{$vendor->id}", ['name' => 'CV Warisan', 'npwp' => '0013345567007000'])
            ->assertOk();
        $this->assertSame('0013345567007000', $vendor->fresh()->npwp);
    }

    // ------------------------------------------------ register dokumen vendor

    /**
     * Pintu ke-8 yang menulis NOMOR NPWP: Dokumen Vendor jenis "NPWP". Nomornya
     * adalah NPWP vendor yang diketik orang dari berkas pindaian, dan sebelum
     * ini ia menerima apa pun ("ABC-123") sementara kolom NPWP di formulir
     * vendor yang sama menolak "ABC" (V3-5). Aturan yang sama, HANYA untuk jenis
     * npwp — nomor SIUP/SBU/akta bebas bentuknya — dan maju-saja pada PUT.
     */
    public function test_a_vendor_document_of_type_npwp_carries_the_npwp_rule_and_other_types_do_not(): void
    {
        $vendor = Vendor::query()->create($this->payload(['npwp' => '01.334.556.7-007.000']));
        $base = ['vendor_id' => $vendor->id, 'name' => 'NPWP PT Vendor Baru'];

        $this->postJson('/api/procurement/vendor-documents', $base + ['doc_type' => 'npwp', 'number' => 'ABC-123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.number.0', ValidNpwp::MESSAGE);

        $this->postJson('/api/procurement/vendor-documents', $base + ['doc_type' => 'npwp', 'number' => '0013345567007000000001'])
            ->assertCreated()
            ->assertJsonPath('data.number', '0013345567007000000001');

        // Jenis lain: nomor apa pun dari berkasnya.
        $this->postJson('/api/procurement/vendor-documents', $base + ['doc_type' => 'siup', 'name' => 'SIUP', 'number' => 'ABC-123'])
            ->assertCreated();
    }

    public function test_a_legacy_npwp_document_number_is_re_savable_unchanged_but_not_changeable_to_a_bad_one(): void
    {
        $vendor = Vendor::query()->create($this->payload(['npwp' => '01.334.556.7-007.000']));
        $document = VendorDocument::query()->create([
            'vendor_id' => $vendor->id, 'doc_type' => 'npwp', 'name' => 'NPWP lama', 'number' => 'N/A',
        ]);

        // Menyunting penerbit dengan nomor lama terkirim apa adanya: bukan penulisan NPWP baru.
        $this->putJson("/api/procurement/vendor-documents/{$document->id}", ['number' => 'N/A', 'issuer' => 'KPP Pratama'])
            ->assertOk();
        $this->assertSame('KPP Pratama', $document->fresh()->issuer);
        $this->assertSame('N/A', $document->fresh()->number);

        $this->putJson("/api/procurement/vendor-documents/{$document->id}", ['number' => 'N/B'])
            ->assertStatus(422)
            ->assertJsonPath('errors.number.0', ValidNpwp::MESSAGE);
        $this->assertSame('N/A', $document->fresh()->number);

        // Mengganti JENIS menjadi npwp pada dokumen lain ikut membawa aturannya —
        // juga bila nomornya dikirim kembali TIDAK berubah (R2-pintu-3: maju-saja
        // hanya untuk dokumen yang SUDAH berjenis npwp), dan juga bila kunci number
        // tidak dikirim sama sekali (R2-pintu-4: nomor tersimpan diperiksa).
        $siup = VendorDocument::query()->create(['vendor_id' => $vendor->id, 'doc_type' => 'siup', 'name' => 'SIUP', 'number' => 'ABC-123']);
        $this->putJson("/api/procurement/vendor-documents/{$siup->id}", ['doc_type' => 'npwp', 'number' => 'ABC-124'])
            ->assertStatus(422)
            ->assertJsonPath('errors.number.0', ValidNpwp::MESSAGE);
        $this->putJson("/api/procurement/vendor-documents/{$siup->id}", ['doc_type' => 'npwp', 'number' => 'ABC-123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.number.0', ValidNpwp::MESSAGE);
        $this->putJson("/api/procurement/vendor-documents/{$siup->id}", ['doc_type' => 'npwp'])
            ->assertStatus(422)
            ->assertJsonPath('errors.number.0', ValidNpwp::MESSAGE);
        $this->assertSame('siup', $siup->fresh()->doc_type->value);
        $this->assertSame('ABC-123', $siup->fresh()->number);

        // …tetapi SIUP tanpa nomor boleh menjadi NPWP tanpa nomor (tidak ada yang diperiksa),
        // dan NPWP boleh kembali menjadi SIUP dengan nomor bebas.
        $blank = VendorDocument::query()->create(['vendor_id' => $vendor->id, 'doc_type' => 'siup', 'name' => 'SIUP kosong', 'number' => null]);
        $this->putJson("/api/procurement/vendor-documents/{$blank->id}", ['doc_type' => 'npwp'])->assertOk();
        $this->putJson("/api/procurement/vendor-documents/{$document->id}", ['doc_type' => 'siup', 'number' => 'ABC'])->assertOk();
    }

    /** R2-pintu-5: Rule::enum menjawab bahasa Indonesia, bukan "The selected Jenis is invalid." */
    public function test_an_unknown_document_type_is_refused_in_indonesian(): void
    {
        $vendor = Vendor::query()->create($this->payload());

        $this->postJson('/api/procurement/vendor-documents', ['vendor_id' => $vendor->id, 'doc_type' => 'paspor', 'name' => 'X', 'number' => 'ABC'])
            ->assertStatus(422)
            ->assertJsonPath('errors.doc_type.0', 'Jenis yang dipilih tidak sah.');

        // Setiap kunci pesan bawaan Laravel punya padanan Indonesia — tidak ada kalimat Inggris yang bocor.
        // Kunci DAUN (Arr::dot), bukan hanya tingkat atas: sub-kunci password.* yang hilang
        // dulu LOLOS HIJAU pada pembanding array_keys (R3-pintu-2).
        $id = require base_path('lang/id/validation.php');
        $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $leaf = fn (array $messages): array => array_keys(Arr::dot(array_diff_key($messages, ['custom' => 1, 'attributes' => 1])));
        $this->assertSame([], array_values(array_diff($leaf($en), $leaf($id))), 'kunci validation.php yang belum diterjemahkan');
    }

    /** Formulir Dokumen Vendor mengatakan aturannya di kolom Nomor — sebelum 422-nya. */
    public function test_the_vendor_document_form_says_the_number_rule_for_type_npwp(): void
    {
        $schema = (string) file_get_contents(public_path('app/js/schema.js'));
        $start = strpos($schema, "'procurement/vendor-documents': {");
        $this->assertNotFalse($start);
        $block = substr($schema, $start, strpos($schema, "'procurement/purchase-requisitions'", $start) - $start);

        $this->assertSame(1, preg_match("/key: 'number', label: 'Nomor', type: 'text',\s*help: '([^']+)'/u", $block, $m), 'kolom Nomor tanpa teks bantuan');
        $this->assertStringContainsString('Jenis NPWP', $m[1]);
        $this->assertStringContainsString('15 / 16 / 22 digit', $m[1]);
        $this->assertStringContainsString('Jenis lain', $m[1]);
    }
}
