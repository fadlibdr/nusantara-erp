<?php

namespace Tests\Feature\Core;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Company;
use Modules\Core\Rules\ValidNpwp;
use Modules\Core\Support\ImportableResources;
use Modules\Core\Support\Npwp;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;

/**
 * P-3b T3b.1 — NPWP 16 digit / NIK / NITKU, tanpa checksum karangan.
 *
 * Tiga bentuk yang diterima, dibedakan HANYA oleh jumlah digitnya, dan
 * ketiganya LITERAL di sini (pelajaran F-6): 15 = NPWP format lama, 16 =
 * NPWP baru (untuk orang pribadi = NIK), 22 = NITKU. DJP tidak menerbitkan
 * digit periksa publik untuk NIK/NPWP-16, jadi tidak ada algoritma yang
 * boleh menolak sebuah nomor 16 digit — dan uji ini menjaga KETIADAAN itu
 * dengan menerima deretan digit yang tidak "terlihat benar".
 *
 * Dua pintu tulis Core diuji di berkas ini juga: profil perusahaan
 * (PUT core/company, validasi inline di controller) dan impor master data
 * (kolom npwp pada vendors/customers/employees). Pintu Crm, Procurement dan
 * HrPayroll punya berkasnya masing-masing di direktori modulnya.
 */
class NpwpTest extends ErpTestCase
{
    // ---------------------------------------------------------- kelas nilai

    public function test_normalisation_strips_dots_dashes_and_spaces_only(): void
    {
        $this->assertSame('012345678012000', Npwp::normalize('01.234.567.8-012.000'));
        $this->assertSame('012345678012000', Npwp::normalize(' 01 234 567 8 012 000 '));
        $this->assertSame('', Npwp::normalize(null));
        $this->assertSame('', Npwp::normalize('   '));
        // Huruf TIDAK dibuang — "N/A" bukan nomor yang kebetulan kosong.
        $this->assertSame('N/A', Npwp::normalize('N/A'));
    }

    public function test_the_three_lengths_and_only_those_are_recognised(): void
    {
        $this->assertSame('npwp15', Npwp::kind('01.234.567.8-012.000'));           // 15 digit
        $this->assertSame('npwp16', Npwp::kind('0012345678012000'));                // 16 digit
        $this->assertSame('npwp16', Npwp::kind('3174051506710001'));                // 16 digit — NIK
        $this->assertSame('nitku', Npwp::kind('0012345678012000000001'));           // 22 digit
        $this->assertSame('nitku', Npwp::kind('0012345678012000-000001'));          // 22 digit, strip diterima

        $this->assertNull(Npwp::kind('01234567801200'));                            // 14
        $this->assertNull(Npwp::kind('00123456780120001'));                         // 17
        $this->assertNull(Npwp::kind('001234567801200000000'));                     // 21
        $this->assertNull(Npwp::kind('00123456780120000000012'));                   // 23
        $this->assertNull(Npwp::kind('N/A'));
        $this->assertNull(Npwp::kind('01.234.567.8-012.00X'));
        $this->assertNull(Npwp::kind(''));
        $this->assertNull(Npwp::kind(null));
    }

    /**
     * SEMUA panjang 1..30 diulang, dan hanya tiga literal yang dikenali. Paku
     * negatif yang hanya menyebut 14/17/21/23 membiarkan mutasi "20 digit =
     * NITKU" lolos hijau di keempat pintu (V3b-6).
     */
    public function test_every_length_other_than_15_16_and_22_is_refused(): void
    {
        foreach (range(1, 30) as $length) {
            $digits = str_repeat('7', $length);
            $expected = match ($length) {
                15 => 'npwp15',
                16 => 'npwp16',
                22 => 'nitku',
                default => null,
            };

            $this->assertSame($expected, Npwp::kind($digits), "{$length} digit");
            $this->assertSame($expected !== null, Npwp::isValid($digits), "{$length} digit");
        }
    }

    /** Tidak ada digit periksa: deretan yang "tidak terlihat benar" tetap sah bila panjangnya sah. */
    public function test_no_checksum_is_invented(): void
    {
        $this->assertTrue(Npwp::isValid('000000000000000'));
        $this->assertTrue(Npwp::isValid('9999999999999999'));
        $this->assertTrue(Npwp::isValid('1234567890123456789012'));
    }

    public function test_display_format_per_kind(): void
    {
        $this->assertSame('01.234.567.8-012.000', Npwp::format('012345678012000'));
        $this->assertSame('01.234.567.8-012.000', Npwp::format('01.234.567.8-012.000'));
        // 16 dan 22 digit ditampilkan sebagai digit utuh — Coretax tidak memakai
        // pemisah untuk keduanya, dan mengarang pengelompokan bukan tugas paket ini.
        $this->assertSame('0012345678012000', Npwp::format('0012345678012000'));
        $this->assertSame('0012345678012000000001', Npwp::format('0012345678012000-000001'));
        // Nilai lama yang tidak dikenali dipulangkan APA ADANYA, bukan null — pembaca tidak menolak.
        $this->assertSame('N/A', Npwp::format('N/A'));
        $this->assertNull(Npwp::format(null));
        $this->assertNull(Npwp::format(''));
    }

    public function test_labels_name_the_three_kinds(): void
    {
        $this->assertSame('NPWP 15 digit (format lama)', Npwp::label('npwp15'));
        $this->assertSame('NPWP 16 digit / NIK', Npwp::label('npwp16'));
        $this->assertSame('NITKU (22 digit)', Npwp::label('nitku'));
        $this->assertNull(Npwp::label(null));
    }

    public function test_the_refusal_sentence_names_all_three_accepted_forms(): void
    {
        foreach (['15 digit', '16 digit', '22 digit', 'NIK', 'NITKU'] as $needle) {
            $this->assertStringContainsString($needle, ValidNpwp::MESSAGE);
        }
    }

    public function test_the_rule_passes_blank_and_the_three_forms_and_fails_everything_else(): void
    {
        $rule = new ValidNpwp;

        foreach ([null, '', '01.234.567.8-012.000', '0012345678012000', '0012345678012000000001'] as $ok) {
            $failed = null;
            $rule->validate('npwp', $ok, function (string $message) use (&$failed): void {
                $failed = $message;
            });
            $this->assertNull($failed, var_export($ok, true));
        }

        foreach (['01234567801200', 'N/A', '12345678901234567'] as $bad) {
            $failed = null;
            $rule->validate('npwp', $bad, function (string $message) use (&$failed): void {
                $failed = $message;
            });
            $this->assertSame(ValidNpwp::MESSAGE, $failed, $bad);
        }
    }

    /**
     * MAJU-SAJA. Nilai yang dikirim kembali PERSIS seperti yang tersimpan
     * bukan penulisan baru: formulir SPA mengirim seluruh baris, dan sunting
     * nomor telepon pada vendor lama ber-NPWP "N/A" tidak boleh terhalang
     * oleh kolom yang tidak disentuh siapa pun.
     */
    public function test_the_rule_lets_an_unchanged_legacy_value_through_but_not_a_changed_one(): void
    {
        $rule = ValidNpwp::unlessUnchanged('N/A');

        $failed = null;
        $rule->validate('npwp', 'N/A', function (string $message) use (&$failed): void {
            $failed = $message;
        });
        $this->assertNull($failed, 'nilai lama yang tidak berubah harus lolos');

        $rule->validate('npwp', 'N/B', function (string $message) use (&$failed): void {
            $failed = $message;
        });
        $this->assertSame(ValidNpwp::MESSAGE, $failed, 'nilai yang BERUBAH dan tidak sah harus ditolak');
    }

    // ------------------------------------------------------ profil perusahaan

    public function test_the_company_profile_refuses_a_malformed_npwp_with_the_sentence(): void
    {
        Sanctum::actingAs($this->adminUser());
        Company::query()->create(['name' => 'PT Nusantara', 'npwp' => '01.234.567.8-012.000']);

        $this->putJson('/api/core/company', ['name' => 'PT Nusantara', 'npwp' => '01.234.567.8-012'])
            ->assertStatus(422)
            ->assertJsonPath('errors.npwp.0', ValidNpwp::MESSAGE);

        $this->assertSame('01.234.567.8-012.000', Company::current()->npwp);
    }

    public function test_the_company_profile_accepts_a_nitku_and_stores_it_as_typed(): void
    {
        Sanctum::actingAs($this->adminUser());
        Company::query()->create(['name' => 'PT Nusantara', 'npwp' => '01.234.567.8-012.000']);

        $this->putJson('/api/core/company', ['name' => 'PT Nusantara', 'npwp' => '0012345678012000000001'])
            ->assertOk();

        $this->assertSame('0012345678012000000001', Company::current()->npwp);
    }

    public function test_the_company_profile_keeps_a_legacy_value_readable_and_re_savable_unchanged(): void
    {
        Sanctum::actingAs($this->adminUser());
        Company::query()->create(['name' => 'PT Nusantara', 'npwp' => 'BELUM ADA']);

        $this->getJson('/api/core/company')->assertOk()->assertJsonPath('data.npwp', 'BELUM ADA');

        // Menyunting kota, dengan NPWP lama terkirim apa adanya: bukan penulisan NPWP baru.
        $this->putJson('/api/core/company', ['name' => 'PT Nusantara', 'npwp' => 'BELUM ADA', 'city' => 'Bekasi'])
            ->assertOk();

        $this->assertSame('Bekasi', Company::current()->city);
        $this->assertSame('BELUM ADA', Company::current()->npwp);

        // …tetapi mengetik nilai lain yang tidak sah tetap ditolak.
        $this->putJson('/api/core/company', ['name' => 'PT Nusantara', 'npwp' => 'BELUM JUGA'])
            ->assertStatus(422)
            ->assertJsonPath('errors.npwp.0', ValidNpwp::MESSAGE);
    }

    // --------------------------------------------------------- impor master

    private function vendorFile(string ...$rows): string
    {
        return base64_encode("kode,nama,npwp,klasifikasi\n".implode("\n", $rows)."\n");
    }

    public function test_the_master_data_importer_refuses_a_malformed_npwp_per_row_and_lands_the_rest(): void
    {
        Sanctum::actingAs($this->adminUser());

        $response = $this->postJson('/api/core/master-data/vendors/import', [
            'filename' => 'vendor.csv',
            'content' => $this->vendorFile(
                'VND-901,PT Sah Lima Belas,01.334.556.7-007.000,material',
                'VND-902,PT Sah Enam Belas,0013345567007000,material',
                'VND-903,PT Salah,01.334.556.7-007,material',
                'VND-904,PT NITKU,0013345567007000000002,material',
            ),
        ])->assertOk();

        $this->assertSame(3, $response->json('data.created'));
        $this->assertSame(1, $response->json('data.skipped'));
        $this->assertStringContainsString(ValidNpwp::MESSAGE, implode(' ', $response->json('data.rows.0.errors')));
        $this->assertNull(Vendor::query()->where('code', 'VND-903')->first());
        $this->assertSame('0013345567007000000002', Vendor::query()->where('code', 'VND-904')->value('npwp'));
    }

    /** Lembar yang TIDAK membawa kolom npwp membiarkan nilai lama apa adanya — jalur sunting massal baris lama. */
    public function test_a_sheet_without_the_npwp_column_leaves_a_legacy_value_alone(): void
    {
        Sanctum::actingAs($this->adminUser());
        Vendor::query()->create(['code' => 'VND-LAMA', 'name' => 'PT Lama', 'npwp' => 'N/A', 'classification' => 'material']);

        $this->postJson('/api/core/master-data/vendors/import', [
            'filename' => 'vendor.csv',
            'content' => base64_encode("kode,nama,kota\nVND-LAMA,PT Lama Sekali,Bekasi\n"),
        ])->assertOk()->assertJsonPath('data.updated', 1);

        $vendor = Vendor::query()->where('code', 'VND-LAMA')->first();
        $this->assertSame('PT Lama Sekali', $vendor->name);
        $this->assertSame('N/A', $vendor->npwp);
    }

    /**
     * MAJU-SAJA DI PINTU KE-7 JUGA. Ekspor aplikasi SELALU membawa kolom npwp,
     * jadi "ekspor → sunting kota di Excel → impor balik" — jalur sunting massal
     * yang menjadi alasan ekspor itu ada — mengirim setiap NPWP lama kembali
     * apa adanya. Baris lama yang nilainya PERSIS sama dengan yang tersimpan
     * bukan penulisan NPWP baru: aturan yang sama dengan keempat pintu PUT
     * (ValidNpwp::unlessUnchanged), bukan aturan yang lebih ketat di pintu
     * yang kebetulan berbeda (temuan V2-1/V3-2/V3b-4).
     */
    public function test_the_apps_own_export_imports_back_unchanged_even_when_a_legacy_npwp_is_in_it(): void
    {
        Sanctum::actingAs($this->adminUser());
        Vendor::query()->create(['code' => 'VND-LAMA', 'name' => 'PT Lama', 'npwp' => 'N/A', 'classification' => 'material', 'city' => 'Depok']);
        Vendor::query()->create(['code' => 'VND-SAH', 'name' => 'PT Sah', 'npwp' => '01.334.556.7-007.000', 'classification' => 'material']);

        $exported = $this->getJson('/api/core/master-data/vendors/export')->assertOk()->getContent();
        $this->assertStringContainsString('N/A', $exported, 'ekspor membawa nilai lama apa adanya');

        $this->postJson('/api/core/master-data/vendors/import', [
            'filename' => 'vendor.csv',
            'content' => base64_encode($exported),
        ])->assertOk()
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('data.skipped', 0);

        $this->assertSame('N/A', Vendor::query()->where('code', 'VND-LAMA')->value('npwp'));
    }

    public function test_a_legacy_row_sent_back_with_its_own_npwp_lands_its_other_edits_while_a_changed_bad_value_is_refused(): void
    {
        Sanctum::actingAs($this->adminUser());
        Vendor::query()->create(['code' => 'VND-LAMA', 'name' => 'PT Lama', 'npwp' => 'N/A', 'classification' => 'material', 'city' => 'Depok']);
        Vendor::query()->create(['code' => 'VND-LAIN', 'name' => 'PT Lain', 'npwp' => 'N/A', 'classification' => 'material', 'city' => 'Depok']);

        $response = $this->postJson('/api/core/master-data/vendors/import', [
            'filename' => 'vendor.csv',
            'content' => base64_encode(
                "kode,nama,npwp,kota\n"
                ."VND-LAMA,PT Lama,N/A,Bekasi\n"          // NPWP lama dikirim kembali apa adanya → kota mendarat
                ."VND-LAIN,PT Lain,N/B,Bogor\n"           // NPWP DIUBAH ke nilai lain yang tidak sah → dilewati
                ."VND-BARU,PT Baru,N/A,Tangerang\n",     // baris BARU: tidak ada nilai tersimpan, aturan penuh
            ),
        ])->assertOk();

        $this->assertSame(1, $response->json('data.updated'));
        $this->assertSame(0, $response->json('data.created'));
        $this->assertSame(2, $response->json('data.skipped'));
        $this->assertSame(['VND-LAIN', 'VND-BARU'], array_column($response->json('data.rows'), 'key'));
        foreach ($response->json('data.rows') as $row) {
            $this->assertStringContainsString(ValidNpwp::MESSAGE, implode(' ', $row['errors']), $row['key']);
        }

        $lama = Vendor::query()->where('code', 'VND-LAMA')->first();
        $this->assertSame('Bekasi', $lama->city);
        $this->assertSame('N/A', $lama->npwp);
        $this->assertSame('Depok', Vendor::query()->where('code', 'VND-LAIN')->value('city'));
        $this->assertNull(Vendor::query()->where('code', 'VND-BARU')->first());
    }

    public function test_every_master_data_resource_with_an_npwp_column_carries_the_rule(): void
    {
        $resources = ImportableResources::all();
        $guarded = [];

        foreach ($resources as $key => $definition) {
            foreach ($definition['columns'] as $column) {
                if ($column['field'] !== 'npwp') {
                    continue;
                }

                $hasRule = array_filter($column['rules'] ?? [], fn ($rule) => $rule instanceof ValidNpwp) !== [];
                $this->assertTrue($hasRule, "kolom npwp pada impor '{$key}' tidak membawa ValidNpwp");
                $guarded[] = $key;
            }
        }

        // Ketiga tabel master yang membawa NPWP — literal, supaya tabel keempat yang
        // suatu hari menambah kolom npwp tanpa aturan terlihat di sini.
        $this->assertSame(['vendors', 'customers', 'employees'], $guarded);
    }
}
