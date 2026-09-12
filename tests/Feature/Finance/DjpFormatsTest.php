<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Modules\Core\Models\Company;
use Modules\Finance\Services\TaxExportService;
use Modules\Finance\Support\DjpFormats;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * P-3b T3b.0 — registri format berkas DJP/BPJS dan label jujurnya.
 *
 * Seluruh paket ini berdiri di atas satu kalimat: sebuah berkas pajak yang
 * belum pernah dicocokkan dengan template resmi TIDAK BOLEH tampil, terunduh,
 * atau dijawab API sebagai "sesuai DJP". Yang dijaga di sini adalah bahwa
 * kalimat itu keluar dari SATU registri dan sampai ke KETIGA permukaannya —
 * jawaban API ikhtisar, muatan setiap ekspor, dan berkas yang diunduh (nama
 * DAN isinya) — bukan hanya ke docblock yang tidak dibaca operator.
 *
 * Angka dan kunci di sini LITERAL (pelajaran F-6): uji yang membaca
 * harapannya dari registri yang diujinya tidak menjaga apa pun.
 */
class DjpFormatsTest extends ErpTestCase
{
    use FinanceFixtures;

    private const EXPECTED_KEYS = [
        'efaktur_csv_legacy',
        'efaktur_coretax_xml',
        'ebupot_unifikasi_csv',
        'ebupot_2126_bulanan',
        'sipp_bpjs',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);

        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'npwp' => '01.234.567.8-012.000',
            'is_pkp' => true,
        ]);
    }

    // --------------------------------------------------------------- registri

    public function test_the_registry_lists_exactly_the_five_formats_the_roadmap_names(): void
    {
        $this->assertSame(self::EXPECTED_KEYS, array_keys(DjpFormats::all()));
    }

    public function test_every_entry_carries_the_fields_the_screen_and_the_api_read(): void
    {
        foreach (DjpFormats::all() as $key => $entry) {
            foreach (['key', 'label', 'status', 'status_label', 'authority', 'source', 'verified', 'verified_against', 'verification', 'awaiting_file', 'downloadable'] as $field) {
                $this->assertArrayHasKey($field, $entry, "{$key} tanpa field {$field}");
            }

            $this->assertSame($key, $entry['key']);
            $this->assertContains($entry['status'], ['ada', 'menunggu template'], "{$key}: status di luar dua nilai yang disepakati");
            $this->assertNotSame('', trim($entry['label']));
            $this->assertNotSame('', trim($entry['source']));
        }
    }

    /**
     * Hari ini (12 Sep 2026) docs/samples/pajak/ tidak berisi satu pun berkas
     * resmi. Maka TIDAK SATU PUN format boleh mengaku terverifikasi — dan
     * kalimatnya harus mengatakan itu dengan huruf yang sama di semua tempat.
     * Uji ini SENGAJA merah pada hari pemilik meletakkan berkasnya dan
     * mengisi verified_against: itulah saatnya ia diperbarui bersama buktinya.
     */
    public function test_today_no_format_claims_verification_and_each_sentence_says_so(): void
    {
        foreach (DjpFormats::all() as $key => $entry) {
            $this->assertFalse($entry['verified'], "{$key} mengaku terverifikasi tanpa berkas contoh di docs/samples/pajak/");
            $this->assertNull($entry['verified_against'], "{$key} menunjuk berkas contoh yang tidak ada");
            $this->assertStringStartsWith('BELUM DIVERIFIKASI terhadap template ', $entry['verification'], $key);
            $this->assertStringContainsString('docs/samples/pajak/README.md', $entry['verification'], $key);
            // Bukan kalimat "registri menunjuk … tetapi berkasnya tidak ada di pohon ini": itu kalimat
            // jujur untuk klaim yang salah, dan hari ini tidak boleh ada klaim sama sekali (V2-4).
            $this->assertStringNotContainsString('tidak ada di pohon ini', $entry['verification'], $key);
            $this->assertStringStartsWith('Belum diverifikasi terhadap template ', $entry['badge_label'], $key);
        }

        // Deklarasi MENTAH, sebelum describe() menurunkan apa pun: hari ini setiap
        // verified_against adalah null — sebuah klaim bertanggal yang tidak pernah dibuat
        // siapa pun tidak boleh lolos hanya karena describe() sudah menurunkannya (V2-4).
        foreach (DjpFormats::declared() as $entry) {
            $this->assertNull($entry['verified_against'], "{$entry['key']} mendeklarasikan verified_against");
        }

        $this->assertSame(
            ['total' => 5, 'verified' => 0, 'label' => '0 dari 5 diverifikasi terhadap template resmi'],
            DjpFormats::summary(),
        );
    }

    public function test_the_two_existing_writers_are_available_and_the_three_planned_ones_wait_for_a_template(): void
    {
        $all = DjpFormats::all();

        $this->assertSame('ada', $all['efaktur_csv_legacy']['status']);
        $this->assertSame('ada', $all['ebupot_unifikasi_csv']['status']);
        $this->assertTrue($all['efaktur_csv_legacy']['downloadable']);
        $this->assertTrue($all['ebupot_unifikasi_csv']['downloadable']);

        foreach (['efaktur_coretax_xml', 'ebupot_2126_bulanan', 'sipp_bpjs'] as $key) {
            $this->assertSame('menunggu template', $all[$key]['status'], $key);
            $this->assertFalse($all[$key]['downloadable'], "{$key} menawarkan unduhan berkas yang tata letaknya tidak diketahui siapa pun");
            $this->assertIsString($all[$key]['awaiting_file'], $key);
            $this->assertStringContainsString('docs/samples/pajak/', $all[$key]['awaiting_file'], $key);
        }
    }

    /** SIPP adalah berkas BPJS, bukan DJP — kalimatnya tidak boleh menyebut otoritas yang salah. */
    public function test_the_sipp_entry_names_bpjs_as_its_authority(): void
    {
        $sipp = DjpFormats::get('sipp_bpjs');

        $this->assertSame('BPJS Ketenagakerjaan', $sipp['authority']);
        $this->assertStringStartsWith('BELUM DIVERIFIKASI terhadap template BPJS Ketenagakerjaan', $sipp['verification']);
        $this->assertSame('DJP', DjpFormats::get('efaktur_csv_legacy')['authority']);
    }

    public function test_an_unknown_key_is_refused_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DjpFormats::get('efaktur_xml_karangan');
    }

    /**
     * Sebuah entri yang MENYATAKAN verified_against tetapi berkasnya tidak ada
     * di pohon (deploy yang lupa menyalin docs/, atau tanggal yang diketik
     * lebih dulu daripada berkasnya) tidak boleh naik menjadi "diverifikasi".
     * describe() adalah fungsi murni supaya kasus ini bisa diuji tanpa kait
     * khusus-uji di registri.
     */
    public function test_a_declared_verification_without_the_file_on_disk_degrades_to_unverified(): void
    {
        $entry = DjpFormats::describe([
            'key' => 'contoh',
            'label' => 'Contoh',
            'status' => 'ada',
            'authority' => 'DJP',
            'source' => 'uji',
            'writer' => true,
            'verified_against' => ['path' => 'docs/samples/pajak/tidak-ada-2026-09-12.csv', 'date' => '2026-09-12'],
            'awaiting_file' => null,
        ]);

        $this->assertFalse($entry['verified']);
        $this->assertNull($entry['verified_against']);
        $this->assertStringStartsWith('BELUM DIVERIFIKASI terhadap template DJP', $entry['verification']);
        $this->assertStringContainsString('tidak-ada-2026-09-12.csv', $entry['verification']);
        $this->assertStringContainsString('tidak ada di pohon ini', $entry['verification']);
        // Teks lencana layar juga dari sini — LITERAL, supaya SPA tidak punya alasan menyusunnya sendiri.
        $this->assertSame('Belum diverifikasi terhadap template DJP', $entry['badge_label']);
    }

    public function test_a_declared_verification_with_the_file_present_is_reported_with_its_path_and_date(): void
    {
        // Berkas yang PASTI ada di pohon; hanya keberadaannya yang dibaca.
        $entry = DjpFormats::describe([
            'key' => 'contoh',
            'label' => 'Contoh',
            'status' => 'ada',
            'authority' => 'DJP',
            'source' => 'uji',
            'writer' => true,
            'verified_against' => ['path' => 'docs/samples/pajak/README.md', 'date' => '2026-09-12'],
            'awaiting_file' => null,
        ]);

        $this->assertTrue($entry['verified']);
        $this->assertSame(['path' => 'docs/samples/pajak/README.md', 'date' => '2026-09-12'], $entry['verified_against']);
        $this->assertStringStartsWith('Diverifikasi terhadap docs/samples/pajak/README.md (2026-09-12)', $entry['verification']);
        $this->assertSame('Diverifikasi 2026-09-12', $entry['badge_label']);
    }

    // ---------------------------------------------------------- berkas unduhan

    public function test_an_unverified_export_carries_the_sentence_in_its_filename_and_as_the_first_line_of_the_file(): void
    {
        $this->assertSame('efaktur-2026-03-belum-diverifikasi.csv', DjpFormats::filename('efaktur_csv_legacy', 'efaktur-2026-03.csv'));
        $this->assertSame('ebupot-2026-03-belum-diverifikasi.csv', DjpFormats::filename('ebupot_unifikasi_csv', 'ebupot-2026-03.csv'));

        $stamped = DjpFormats::stampCsv('efaktur_csv_legacy', "FK,KD\nFK,01\n");
        $lines = explode("\n", $stamped);

        $this->assertStringStartsWith('# BELUM DIVERIFIKASI terhadap template DJP', $lines[0]);
        // V3b-7: baris '#' itu membuat berkas tidak bisa diimpor apa adanya — ia harus
        // mengatakannya sendiri, bukan menyerahkannya pada penolakan importer.
        $this->assertStringEndsWith('Hapus baris pertama ini sebelum mengimpor — importer mengharapkan header di baris 1.', $lines[0]);
        $this->assertSame(DjpFormats::FIRST_LINE_INSTRUCTION, 'Hapus baris pertama ini sebelum mengimpor — importer mengharapkan header di baris 1.');
        // Kolom data tidak disentuh: baris kedua dan seterusnya adalah berkas lama apa adanya.
        $this->assertSame(['FK,KD', 'FK,01', ''], array_slice($lines, 1));
    }

    /** V3b-7: kalimat "hapus baris pertama" juga sampai ke layar — dari registri (`file_note`), bukan disusun SPA. */
    public function test_the_registry_tells_the_screen_about_the_first_line_only_for_an_unverified_file_that_exists(): void
    {
        $unverified = DjpFormats::get('efaktur_csv_legacy');
        $this->assertIsString($unverified['file_note']);
        $this->assertStringStartsWith('Baris pertama berkas ini adalah komentar "# BELUM DIVERIFIKASI terhadap template DJP', $unverified['file_note']);
        $this->assertStringEndsWith(DjpFormats::FIRST_LINE_INSTRUCTION, $unverified['file_note']);
        $this->assertStringContainsString('Hapus baris pertama ini sebelum mengimpor', $unverified['file_note']);

        // Format yang menunggu template tidak punya berkas, jadi tidak ada baris pertama untuk dihapus.
        $this->assertNull(DjpFormats::get('efaktur_coretax_xml')['file_note']);
        $this->assertNull(DjpFormats::get('sipp_bpjs')['file_note']);

        // Berkas yang sudah diverifikasi dikembalikan apa adanya — tanpa baris '#', tanpa kalimat.
        $verified = DjpFormats::describe([
            'key' => 'contoh', 'label' => 'Contoh', 'status' => 'ada', 'authority' => 'DJP', 'source' => 'uji',
            'writer' => true, 'verified_against' => ['path' => 'docs/samples/pajak/README.md', 'date' => '2026-09-12'], 'awaiting_file' => null,
        ]);
        $this->assertNull($verified['file_note']);

        $screen = (string) file_get_contents(public_path('app/js/views/taxexport.js'));
        // Struktural (R2-kejujuran-2): cabangnya persis `exp.format.file_note ? el(` — mutasi `&& false` merah.
        $this->assertMatchesRegularExpression('/exp\.format\.file_note\s*\?\s*el\(\'\.card-body\.djp-file-note\'/', $screen,
            'taxexport.js tidak menggambar .djp-file-note dari exp.format.file_note');
    }

    public function test_a_verified_export_is_left_exactly_as_the_writer_produced_it(): void
    {
        $verified = DjpFormats::describe([
            'key' => 'contoh',
            'label' => 'Contoh',
            'status' => 'ada',
            'authority' => 'DJP',
            'source' => 'uji',
            'writer' => true,
            'verified_against' => ['path' => 'docs/samples/pajak/README.md', 'date' => '2026-09-12'],
            'awaiting_file' => null,
        ]);

        $this->assertSame('efaktur-2026-03.csv', DjpFormats::filenameFor($verified, 'efaktur-2026-03.csv'));
        $this->assertSame("FK,KD\n", DjpFormats::stampCsvFor($verified, "FK,KD\n"));
    }

    public function test_the_e_faktur_and_e_bupot_exports_carry_their_format_entry_and_the_stamped_file(): void
    {
        $customer = $this->makeCustomer(['name' => 'PT Graha Sentosa', 'npwp' => '01.234.567.8-011.000']);
        $contract = $this->makeContract($customer);
        $invoice = $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'description' => 'Termin 1',
            'dpp' => 100_000_000,
            'ppn_rate' => 11.0,
            'invoice_date' => '2026-03-15',
        ]));
        $this->arInvoices()->registerFakturPajak($invoice, '010.000-26.00000001');

        $export = app(TaxExportService::class)->eFaktur(2026, 3);

        $this->assertSame('efaktur_csv_legacy', $export['format']['key']);
        $this->assertFalse($export['format']['verified']);
        $this->assertSame('efaktur-2026-03-belum-diverifikasi.csv', $export['filename']);
        $this->assertStringStartsWith('# BELUM DIVERIFIKASI terhadap template DJP', $export['csv']);
        // …dan sesudah baris komentar, header FK lama berdiri persis di tempatnya.
        $this->assertSame('FK,KD_JENIS_TRANSAKSI', substr(explode("\n", $export['csv'])[1], 0, 21));

        $bupot = app(TaxExportService::class)->eBupot(2026, 3);

        $this->assertSame('ebupot_unifikasi_csv', $bupot['format']['key']);
        $this->assertSame('ebupot-2026-03-belum-diverifikasi.csv', $bupot['filename']);
        $this->assertStringStartsWith('# BELUM DIVERIFIKASI terhadap template DJP', $bupot['csv']);
        $this->assertStringStartsWith('NOMOR_BUKTI_POTONG,', explode("\n", $bupot['csv'])[1]);
    }

    // ----------------------------------------------------------------- API

    public function test_the_overview_api_answers_with_the_whole_registry_and_the_sentence_per_export(): void
    {
        $response = $this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/tax-exports?year=2026&month=3')
            ->assertOk();

        $formats = $response->json('data.formats');

        $this->assertSame(self::EXPECTED_KEYS, array_column($formats, 'key'));

        foreach ($formats as $format) {
            $this->assertFalse($format['verified'], $format['key']);
            $this->assertStringStartsWith('BELUM DIVERIFIKASI terhadap template ', $format['verification'], $format['key']);
            $this->assertStringStartsWith('Belum diverifikasi terhadap template ', $format['badge_label'], $format['key']);
        }

        $this->assertSame('0 dari 5 diverifikasi terhadap template resmi', $response->json('data.formats_summary.label'));

        $this->assertStringStartsWith('BELUM DIVERIFIKASI terhadap template DJP', $response->json('data.efaktur.format.verification'));
        $this->assertStringStartsWith('BELUM DIVERIFIKASI terhadap template DJP', $response->json('data.ebupot.format.verification'));
        $this->assertStringContainsString('Hapus baris pertama ini sebelum mengimpor', (string) $response->json('data.efaktur.format.file_note'));
        $this->assertStringContainsString('Hapus baris pertama ini sebelum mengimpor', (string) $response->json('data.ebupot.format.file_note'));
        $this->assertSame('efaktur-2026-03-belum-diverifikasi.csv', $response->json('data.efaktur.filename'));

        // Format yang menunggu template menyebut berkas yang harus diletakkan — dan tidak bisa diunduh.
        $xml = collect($formats)->firstWhere('key', 'efaktur_coretax_xml');
        $this->assertFalse($xml['downloadable']);
        $this->assertStringContainsString('docs/samples/pajak/efaktur-coretax-xml-', $xml['awaiting_file']);
    }

    // ------------------------------------------------------------- dokumen

    /**
     * README-nya adalah daftar berkas yang harus diunduh pemilik/konsultan —
     * dan setiap nama berkas yang registri tunggu harus tertulis di sana,
     * supaya tidak ada dua daftar yang hanyut satu sama lain.
     */
    public function test_the_samples_readme_names_every_awaited_file_and_never_draws_a_layout(): void
    {
        $path = base_path('docs/samples/pajak/README.md');
        $this->assertFileExists($path);
        $readme = (string) file_get_contents($path);

        foreach (DjpFormats::all() as $key => $entry) {
            $this->assertStringContainsString("`{$key}`", $readme, "README tidak menyebut kunci registri {$key}");
            $this->assertStringContainsString($entry['sample_stem'], $readme, "README tidak menyebut pola nama berkas {$entry['sample_stem']}");
        }

        // Tidak satu pun tata letak yang dikarang: nama kolom skema lama tidak boleh ada di README.
        foreach (['KD_JENIS_TRANSAKSI', 'NOMOR_BUKTI_POTONG', 'FG_PENGGANTI', '<xml', '<Faktur'] as $layoutMarker) {
            $this->assertStringNotContainsString($layoutMarker, $readme, "README menggambar tata letak ({$layoutMarker}) — itu yang dilarang paket ini");
        }
    }

    /**
     * T3b.3 — sapuan kejujuran pada yang SUDAH ada. Tiga kalimat yang harus
     * tetap berdiri: NTPN adalah entri MANUAL (kalender pajak + service), tabel
     * TER ditandai perlu dicek dari tempat tabel itu hidup (Pph21TerService,
     * BUKAN config/erp.php — docblock lama TaxExportService menunjuk ke sana
     * dan dibetulkan), dan tidak satu pun berkas aplikasi menjanjikan NTPN
     * otomatis atau berkas "sesuai DJP".
     */
    public function test_ntpn_stays_manual_and_nothing_promises_automation_or_djp_conformance(): void
    {
        $kalender = (string) file_get_contents(public_path('app/js/views/kalenderpajak.js'));
        $this->assertStringContainsString('NTPN diketik dari SSP/BPN asli, tidak ada integrasi e-filing', $kalender);
        $this->assertStringContainsString('dipilih manual, tidak ada yang otomatis', $kalender);

        $obligations = (string) file_get_contents(base_path('Modules/Finance/Services/TaxObligationService.php'));
        $this->assertStringContainsString('harus mencantumkan NTPN dari SSP/BPN-nya', $obligations);

        $ter = (string) file_get_contents(base_path('Modules/HrPayroll/Services/Pph21TerService.php'));
        $this->assertStringContainsString('verify against', $ter);
        $this->assertStringContainsString("'rate' => 34.0", $ter, 'tabel TER masih ada di Pph21TerService');
        $this->assertStringNotContainsString("'ter'", (string) file_get_contents(config_path('erp.php')),
            'config/erp.php TIDAK memuat tabel TER — bila suatu hari dipindah ke sana, kalimat VERIFICATION_NOTE harus ikut pindah');

        $taxExport = (string) file_get_contents(base_path('Modules/Finance/Services/TaxExportService.php'));
        $this->assertStringContainsString('the brackets live in that service, NOT in', $taxExport,
            'docblock TaxExportService kembali menunjuk config/erp.php untuk tabel TER');

        // Sapuan DIBATASI pada permukaan pajak yang dijaga paket ini (pelajaran 4:
        // paku yang menyapu seluruh aplikasi merah ketika modul lain menulis
        // "belum sesuai DJP" yang wajar — V2-8), TIDAK peka huruf besar (mutasi
        // "Sesuai DJP" lolos hijau — V3b-2), dan mengabaikan negasi wajar.
        $promises = [];
        foreach ([
            public_path('app/js/views/taxexport.js'),
            public_path('app/js/views/rekappph21.js'),
            public_path('app/js/views/kalenderpajak.js'),
            base_path('Modules/Finance/Support/DjpFormats.php'),
            base_path('Modules/Finance/Services/TaxExportService.php'),
            base_path('Modules/Finance/Services/PeriodCloseService.php'),
            base_path('Modules/Finance/Services/TaxObligationService.php'),
            base_path('Modules/Finance/Http/Controllers/TaxExportController.php'),
            base_path('Modules/HrPayroll/Services/Pph21RecapService.php'),
            base_path('Modules/HrPayroll/Http/Controllers/Pph21RecapController.php'),
        ] as $file) {
            $this->assertFileExists($file);
            $code = (string) file_get_contents($file);
            foreach ([
                'NTPN otomatis', 'otomatis dari DJP', 'siap Coretax', 'sesuai DJP', 'sesuai Coretax', 'sesuai template',
                'siap diekspor ke', 'siap dilaporkan ke', 'siap diimpor ke', 'siap dikirim ke', 'format sudah cocok',
                'diterima DJP', 'diterima Coretax',
            ] as $needle) {
                // Komentar yang MENJELASKAN larangan boleh menyebut frasanya (guillemet); yang dijaga
                // adalah string yang tampil — termasuk template literal. Negasi wajar ("belum sesuai DJP",
                // "tidak sesuai DJP", "bukan …") bukan janji dan tidak ditangkap.
                $pattern = '/[\'"`][^\'"`\n]*(?<!belum |tidak |bukan |tanpa )'.preg_quote($needle, '/').'[^\'"`\n]*[\'"`]/iu';
                if (preg_match($pattern, $code) === 1) {
                    $promises[] = basename($file).': '.$needle;
                }
            }
        }

        $this->assertSame([], $promises, 'kalimat yang menjanjikan sesuatu yang tidak terjadi');
    }

    /** Layar Ekspor Pajak membaca registri dari API — bukan kalimat yang dikarang di SPA. */
    public function test_the_tax_export_screen_reads_the_registry_from_the_api(): void
    {
        $screen = (string) file_get_contents(public_path('app/js/views/taxexport.js'));

        $this->assertStringContainsString('payload.formats', $screen, 'taxexport.js tidak membaca data.formats dari API');
        $this->assertStringContainsString('.djp-format', $screen, 'taxexport.js tidak menggambar satu blok per format registri');
        $this->assertStringContainsString('exp.format.verification', $screen, 'taxexport.js tidak menampilkan kalimat verifikasi ekspor dari API');
        $this->assertStringNotContainsString('Tata letak kolom mengikuti skema impor', $screen,
            'kalimat lama yang dikarang SPA masih ada — kalimatnya harus datang dari registri lewat API');

        // Lencana per format dan lencana hitungan di kepala kartu: teks dari server, apa adanya.
        // Sebelum ini SPA menyusun `Belum diverifikasi terhadap template ${authority}` sendiri, dan
        // mutasi menjadi lencana hijau "Sesuai DJP" LOLOS HIJAU di suite PHP (V3b-2/V2-5).
        $this->assertStringContainsString('format.badge_label', $screen, 'taxexport.js tidak membaca badge_label dari API');
        $this->assertStringContainsString('payload.formats_summary', $screen, 'taxexport.js tidak membaca formats_summary dari API');
        $this->assertDoesNotMatchRegularExpression('/[\'"`][^\'"`\n]*(diverifikasi|sesuai|coretax)/iu', $screen,
            'taxexport.js menyusun sendiri kalimat verifikasi/kesesuaian — kalimat itu hanya boleh datang dari registri lewat API');

        // Tombol unduh HANYA dari `downloadable` server: satu literal, di dalam cabang exp.format.downloadable.
        // Mutasi yang menambah tombol Unduh pada format "menunggu template" LOLOS HIJAU sebelum ini (V3b-5).
        $this->assertSame(1, preg_match_all('/Unduh/u', $screen), 'taxexport.js punya lebih dari satu tombol/teks Unduh');
        $this->assertMatchesRegularExpression('/exp\.format\.downloadable\s*\?[^;]*Unduh CSV/su', $screen,
            'tombol Unduh CSV tidak digerbangi exp.format.downloadable');
    }

    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Petugas Pajak',
            'email' => str()->random(8).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
