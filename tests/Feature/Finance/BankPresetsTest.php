<?php

namespace Tests\Feature\Finance;

use InvalidArgumentException;
use Modules\Finance\Support\BankPresets;
use Tests\ErpTestCase;

/**
 * Registri preset bawaan per bank (P-3c, T3c.0) — pola DjpFormats (P-3b).
 *
 * Yang dipaku: PERSIS empat kunci literal; hari ini tidak satu pun punya berkas
 * ekspor nyata di docs/samples/bank/ sehingga tidak satu pun bisa dipilih
 * sebagai pemetaan; kalimatnya menyebut folder dan pola nama berkas yang
 * ditunggu; klaim yang berkasnya tidak ada di pohon diturunkan otomatis;
 * contoh demo di docs/samples/ disebut sebagai CONTOH DEMO, bukan preset; dan
 * README folder itu menyebut setiap kunci + pola nama tanpa menggambar satu
 * tata letak kolom bank pun.
 */
class BankPresetsTest extends ErpTestCase
{
    public function test_the_registry_lists_exactly_the_four_banks_the_roadmap_names(): void
    {
        $this->assertSame(['bca', 'mandiri', 'bni', 'bri'], array_keys(BankPresets::all()));
        $this->assertSame(['bca', 'mandiri', 'bni', 'bri'], array_column(BankPresets::forApi(), 'key'));
    }

    public function test_today_no_bank_has_a_real_export_file_so_none_is_selectable(): void
    {
        $this->assertDirectoryExists(base_path(BankPresets::SAMPLES_DIR));

        foreach (BankPresets::declared() as $entry) {
            $this->assertNull($entry['verified_against'], "{$entry['key']} mengklaim verified_against tanpa berkas ekspor nyata");
            $this->assertNull($entry['mapping'], "{$entry['key']} membawa pemetaan yang dikarang");
        }

        foreach (BankPresets::all() as $key => $entry) {
            $this->assertFalse($entry['verified'], $key);
            $this->assertFalse($entry['selectable'], "{$key} bisa dipilih sebagai pemetaan tanpa berkas ekspor nyata");
            $this->assertNull($entry['mapping'], $key);
            $this->assertSame(BankPresets::STATUS_MENUNGGU, $entry['status'], $key);
            $this->assertSame('Menunggu berkas ekspor nyata', $entry['status_label'], $key);
            $this->assertSame('Belum ada berkas ekspor nyata', $entry['badge_label'], $key);
            $this->assertStringStartsWith(BankPresets::UNVERIFIED_PREFIX, $entry['verification'], $key);
            $this->assertStringContainsString('docs/samples/bank/', $entry['verification'], $key);
            $this->assertStringContainsString("docs/samples/bank/{$key}-<kanal>-<YYYY-MM-DD>", $entry['awaiting_file'], $key);
            $this->assertSame("{$key}-<kanal>-<YYYY-MM-DD>", $entry['sample_stem'], $key);
        }

        $summary = BankPresets::summary();
        $this->assertSame(4, $summary['total']);
        $this->assertSame(0, $summary['verified']);
        $this->assertSame('0 dari 4 bank punya berkas ekspor nyata', $summary['label']);
    }

    public function test_every_entry_carries_the_fields_the_screen_and_the_api_read(): void
    {
        foreach (BankPresets::all() as $key => $entry) {
            foreach ([
                'key', 'label', 'bank', 'channels', 'status', 'status_label', 'verified', 'verified_against',
                'verification', 'badge_label', 'selectable', 'mapping', 'awaiting_file', 'sample_stem', 'demo_note',
            ] as $field) {
                $this->assertArrayHasKey($field, $entry, "{$key} tanpa field {$field}");
            }

            $this->assertNotEmpty($entry['channels'], "{$key} tidak menyebut kanal ekspor yang ditunggu");
        }
    }

    /** Dua contoh demo di docs/samples/ disebut apa adanya: contoh demo, bukan berkas bank. */
    public function test_the_demo_samples_are_named_as_demo_samples_and_never_promoted_to_presets(): void
    {
        $bca = BankPresets::get('bca');
        $this->assertSame(
            'docs/samples/rekening-koran-bca-2026-04.csv adalah contoh demo yang cocok dengan data demo — bukan berkas ekspor bank, dan bukan preset.',
            $bca['demo_note'],
        );
        $this->assertFalse($bca['selectable']);

        $mandiri = BankPresets::get('mandiri');
        $this->assertSame(
            'docs/samples/rekening-koran-mandiri-2026-02.sta adalah contoh demo MT940 yang cocok dengan data demo — bukan berkas ekspor bank, dan bukan preset.',
            $mandiri['demo_note'],
        );

        $this->assertNull(BankPresets::get('bni')['demo_note']);
        $this->assertNull(BankPresets::get('bri')['demo_note']);
    }

    public function test_an_unknown_key_is_refused_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Preset bank [cimb] tidak terdaftar di BankPresets.');

        BankPresets::get('cimb');
    }

    /** describe() murni: klaim yang berkasnya tidak ada di pohon diturunkan, dan pemetaannya ikut ditahan. */
    public function test_a_declared_verification_without_the_file_on_disk_degrades_and_withholds_the_mapping(): void
    {
        $entry = BankPresets::describe([
            'key' => 'bni', 'label' => 'BNI', 'bank' => 'Bank Negara Indonesia', 'channels' => ['BNIDirect'],
            'verified_against' => ['path' => 'docs/samples/bank/bni-bnidirect-2026-09-30.csv', 'date' => '2026-09-30', 'by' => 'pemilik'],
            'mapping' => ['delimiter' => ';', 'skip_rows' => 1, 'date_column' => 0, 'date_format' => 'dd/mm/yyyy',
                'amount_mode' => 'debit_credit', 'debit_column' => 2, 'credit_column' => 3, 'balance_column' => 4, 'number_format' => 'id'],
            'demo_note' => null,
        ]);

        $this->assertFalse($entry['verified']);
        $this->assertFalse($entry['selectable']);
        $this->assertNull($entry['mapping']);
        $this->assertNull($entry['verified_against']);
        $this->assertSame(
            'BELUM ADA BERKAS EKSPOR NYATA Bank Negara Indonesia — registri menunjuk docs/samples/bank/bni-bnidirect-2026-09-30.csv (2026-09-30, pemilik) tetapi berkasnya tidak ada di pohon ini; letakkan berkas ekspor nyata sesuai docs/samples/bank/README.md.',
            $entry['verification'],
        );
        $this->assertSame('Belum ada berkas ekspor nyata', $entry['badge_label']);
    }

    /**
     * …dan klaim yang berkasnya ADA — di docs/samples/bank/ dengan nama <kunci>-<kanal>-<YYYY-MM-DD>.<ekstensi> —
     * naik menjadi terverifikasi, dengan pemetaannya dan siapa yang memverifikasi. Berkasnya dibuat sementara
     * (folder itu hari ini hanya berisi README, dipaku uji lain) dan dihapus lagi.
     */
    public function test_a_declared_verification_with_a_real_export_file_present_is_reported_with_path_date_and_person(): void
    {
        $mapping = ['delimiter' => ';', 'skip_rows' => 1, 'date_column' => 0, 'date_format' => 'dd/mm/yyyy',
            'amount_mode' => 'debit_credit', 'debit_column' => 2, 'credit_column' => 3, 'balance_column' => 4, 'number_format' => 'id'];
        $path = 'docs/samples/bank/bri-brimo-2026-09-30.csv';
        file_put_contents(base_path($path), "sementara\n");

        try {
            $entry = BankPresets::describe([
                'key' => 'bri', 'label' => 'BRI', 'bank' => 'Bank Rakyat Indonesia', 'channels' => ['BRImo Bisnis'],
                'verified_against' => ['path' => $path, 'date' => '2026-09-30', 'by' => 'pemilik'],
                'mapping' => $mapping,
                'demo_note' => null,
            ]);
            $bare = BankPresets::describe([
                'key' => 'bri', 'label' => 'BRI', 'bank' => 'Bank Rakyat Indonesia', 'channels' => ['BRImo Bisnis'],
                'verified_against' => ['path' => $path, 'date' => '2026-09-30', 'by' => 'pemilik'],
                'mapping' => null,
                'demo_note' => null,
            ]);
        } finally {
            unlink(base_path($path));
        }

        $this->assertTrue($entry['verified']);
        $this->assertTrue($entry['selectable']);
        $this->assertSame($mapping, $entry['mapping']);
        $this->assertSame(['path' => $path, 'date' => '2026-09-30', 'by' => 'pemilik'], $entry['verified_against']);
        $this->assertSame(
            'Diverifikasi terhadap docs/samples/bank/bri-brimo-2026-09-30.csv (2026-09-30, pemilik) — cocokkan ulang bila Bank Rakyat Indonesia mengubah tata letak ekspornya; register verifikasi di docs/samples/bank/README.md §4.',
            $entry['verification'],
        );
        $this->assertSame('Diverifikasi 2026-09-30', $entry['badge_label']);
        $this->assertNull($entry['awaiting_file']);
        $this->assertSame(BankPresets::STATUS_ADA, $entry['status']);

        // Berkas ada tetapi pemetaannya belum ditulis: terverifikasi bukan berarti bisa dipilih.
        $this->assertTrue($bare['verified']);
        $this->assertFalse($bare['selectable']);
        $this->assertNull($bare['mapping']);
    }

    /**
     * V-preset-1: berkas yang ADA di pohon tetapi bukan berkas ekspor nyata menurut aturan README —
     * contoh demo di docs/samples/, README.md folder itu sendiri, nama yang tidak berpola, tanggal
     * yang bukan YYYY-MM-DD — TIDAK menaikkan apa pun: verified false, mapping ditahan, kalimatnya
     * menyebut jalur yang salah. Inilah yang menjaga "contoh demo tidak dinaikkan menjadi preset".
     */
    public function test_a_file_that_exists_but_is_not_a_real_export_under_samples_bank_never_verifies(): void
    {
        $mapping = ['delimiter' => ';', 'skip_rows' => 1, 'date_column' => 0, 'date_format' => 'dd/mm/yyyy',
            'amount_mode' => 'debit_credit', 'debit_column' => 3, 'credit_column' => 4, 'balance_column' => 5, 'number_format' => 'id'];
        $this->assertFileExists(base_path('docs/samples/rekening-koran-bca-2026-04.csv'));

        $demo = BankPresets::describe([
            'key' => 'bca', 'label' => 'BCA', 'bank' => 'Bank Central Asia', 'channels' => ['KlikBCA Bisnis'],
            'verified_against' => ['path' => 'docs/samples/rekening-koran-bca-2026-04.csv', 'date' => '2026-09-12', 'by' => 'agen'],
            'mapping' => $mapping,
            'demo_note' => null,
        ]);

        $this->assertFalse($demo['verified']);
        $this->assertFalse($demo['selectable']);
        $this->assertNull($demo['mapping']);
        $this->assertNull($demo['verified_against']);
        $this->assertSame('Belum ada berkas ekspor nyata', $demo['badge_label']);
        $this->assertSame(BankPresets::STATUS_MENUNGGU, $demo['status']);
        $this->assertSame(
            'BELUM ADA BERKAS EKSPOR NYATA Bank Central Asia — registri menunjuk docs/samples/rekening-koran-bca-2026-04.csv (2026-09-12, agen), tetapi itu bukan berkas ekspor nyata menurut docs/samples/bank/README.md: yang dihitung hanya docs/samples/bank/bca-<kanal>-<YYYY-MM-DD>.<ekstensi> dengan tanggal YYYY-MM-DD; contoh demo tidak dinaikkan menjadi preset.',
            $demo['verification'],
        );
        $this->assertStringContainsString('docs/samples/bank/bca-<kanal>-<YYYY-MM-DD>', $demo['awaiting_file']);

        foreach ([
            ['docs/samples/bank/README.md', '2026-09-12'],          // README folder itu, bukan berkas ekspor
            ['docs/samples/bank/bri-brimo-2026-09-30.csv', 'x'],    // nama benar, tanggal bukan YYYY-MM-DD
            ['docs/samples/bank/bca-klikbca-2026-09-30.csv', '2026-09-30'],   // kunci lain (bri ≠ bca)
            ['docs/samples/bank/brimo-2026-09-30.csv', '2026-09-30'],         // tanpa kunci
        ] as [$path, $date]) {
            $entry = BankPresets::describe([
                'key' => 'bri', 'label' => 'BRI', 'bank' => 'Bank Rakyat Indonesia', 'channels' => ['BRImo Bisnis'],
                'verified_against' => ['path' => $path, 'date' => $date, 'by' => 'pemilik'],
                'mapping' => $mapping,
                'demo_note' => null,
            ]);
            $this->assertFalse($entry['verified'], $path);
            $this->assertFalse($entry['selectable'], $path);
            $this->assertNull($entry['mapping'], $path);
            $this->assertStringContainsString("registri menunjuk {$path} ({$date}, pemilik), tetapi itu bukan berkas ekspor nyata", $entry['verification'], $path);
        }

        $this->assertTrue(BankPresets::evidencePath('bri', 'docs/samples/bank/bri-brimo-2026-09-30.csv'));
        $this->assertTrue(BankPresets::evidencePath('mandiri', 'docs/samples/bank/mandiri-kopra-2026-10-01.sta'));
        $this->assertFalse(BankPresets::evidencePath('bri', 'docs/samples/bank/README.md'));
        $this->assertFalse(BankPresets::evidencePath('bca', 'docs/samples/rekening-koran-bca-2026-04.csv'));
        $this->assertFalse(BankPresets::evidencePath('bri', 'docs/samples/bank/sub/bri-brimo-2026-09-30.csv'));
        $this->assertFalse(BankPresets::evidencePath('bri', '../docs/samples/bank/bri-brimo-2026-09-30.csv'));
    }

    /**
     * README-nya daftar belanja pemilik: satu baris per bank + kanal ekspor,
     * konvensi nama bertanggal, siapa memverifikasi — dan TIDAK memuat nama
     * kolom bank mana pun sebagai fakta (itulah yang dilarang paket ini).
     */
    public function test_the_samples_readme_names_every_bank_and_its_file_pattern_and_never_draws_a_layout(): void
    {
        $path = base_path(BankPresets::README);
        $this->assertFileExists($path);
        $readme = (string) file_get_contents($path);

        foreach (BankPresets::all() as $key => $entry) {
            $this->assertStringContainsString("`{$key}`", $readme, "README tidak menyebut kunci registri {$key}");
            $this->assertStringContainsString($entry['sample_stem'], $readme, "README tidak menyebut pola nama berkas {$entry['sample_stem']}");
        }

        $this->assertStringContainsStringIgnoringCase('contoh demo', $readme, 'README tidak menyebut dua berkas contoh demo sebagai contoh demo');
        $this->assertStringContainsString('rekening-koran-bca-2026-04.csv', $readme);
        $this->assertStringContainsString('rekening-koran-mandiri-2026-02.sta', $readme);

        // Tata letak tidak dikarang: baris judul/pemetaan kolom sebuah bank tidak boleh tertulis di README.
        foreach (['Tanggal;', ';Saldo', ';Kredit', 'Kolom 1', 'Kolom tanggal', 'date_column', 'balance_column', '| Kolom'] as $layoutMarker) {
            $this->assertStringNotContainsString($layoutMarker, $readme, "README menggambar tata letak ({$layoutMarker}) — itu yang dilarang paket ini");
        }
    }

    /** Folder sampel ada di pohon (supaya deploy yang menyalin docs/ ikut membawanya) dan hari ini hanya berisi README. */
    public function test_the_bank_samples_folder_holds_only_the_readme_today(): void
    {
        $files = array_values(array_diff(scandir(base_path(BankPresets::SAMPLES_DIR)) ?: [], ['.', '..']));

        $this->assertSame(['README.md'], $files,
            'docs/samples/bank/ berisi berkas lain — bila itu berkas ekspor nyata, isi verified_against + mapping di BankPresets dan perbarui uji ini');
    }
}
