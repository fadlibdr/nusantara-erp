<?php

namespace Tests\Feature\HrPayroll;

use Tests\ErpTestCase;

/**
 * KABEL SPA LAYAR TIMESHEET, DAN KALIMAT YANG TIDAK BOLEH DIKARANG KLIEN
 * (F-5, T5.4).
 *
 * Pelajaran yang berulang di kampanye ini: sebuah rilis SPA belum terverifikasi
 * sampai berkasnya benar-benar DIMUAT, dan sebuah layar baru yang lupa
 * didaftarkan di tiga tempat (rute app.js, menu schema.js, daftar SHELL sw.js)
 * akan bekerja daring lalu setengah mati saat luring, tanpa suara.
 *
 * Dua hal lain yang dipaku di sini karena hanya sumbernya yang bisa
 * membuktikannya:
 *
 *  - GERBANGNYA. Daftar satu periode dijaga hr.view (isinya jam datang dan jam
 *    pulang orang — data pribadi yang setara dengan register absensi sejak
 *    F-4), sementara "Timesheet Saya" TANPA gerbang, karena tukang, teknisi
 *    dan pengemudi tidak memegang satu pun izin hr.*.
 *  - KEADAAN KOSONG. Produksi memegang nol baris absensi hari ini, jadi
 *    keadaan kosong BUKAN kasus tepi di sini: ia keadaan pertama yang dilihat
 *    orang. Layar harus mengatakan "registernya belum berisi", bukan
 *    menggambar tabel berisi nol.
 */
class TimesheetSpaWiringTest extends ErpTestCase
{
    private const VIEW = 'public/app/js/views/timesheet.js';

    private const APP = 'public/app/js/app.js';

    private const SCHEMA = 'public/app/js/schema.js';

    private const WORKER = 'public/app/sw.js';

    private function source(string $path): string
    {
        $full = base_path($path);
        $this->assertFileExists($full);

        return (string) file_get_contents($full);
    }

    /** Kode tanpa komentar: sebuah janji yang hanya ada di komentar bukan janji. */
    private function code(string $path): string
    {
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $this->source($path));

        return (string) preg_replace('#^\s*//.*$#m', '', $source);
    }

    public function test_the_screen_is_registered_in_all_three_places_a_screen_has_to_be(): void
    {
        $app = $this->code(self::APP);
        $schema = $this->code(self::SCHEMA);
        $worker = $this->source(self::WORKER);

        $this->assertStringContainsString("from './views/timesheet.js'", $app, 'app.js tidak mengimpor layarnya.');
        $this->assertStringContainsString("route('timesheet'", $app);
        $this->assertStringContainsString("route('timesheet-saya'", $app);
        $this->assertStringContainsString("route: 'timesheet'", $schema, 'Menu SDM & Payroll tidak memuat layarnya.');
        $this->assertStringContainsString("route: 'timesheet-saya'", $schema, 'Menu Ringkasan tidak memuat "Timesheet Saya".');
        $this->assertStringContainsString(
            "'js/views/timesheet.js'",
            $worker,
            'Berkas layar tidak terdaftar di SHELL sw.js: aplikasi tetap jalan daring lalu setengah '
            .'mati saat luring, tanpa suara.',
        );
    }

    public function test_the_shell_version_went_up_because_the_shell_changed(): void
    {
        $this->assertSame(1, preg_match("~const SHELL_VERSION = '(\\d+)';~", $this->source(self::WORKER), $version));
        $this->assertGreaterThanOrEqual(
            15,
            (int) $version[1],
            'SHELL_VERSION turun di bawah 15, versi yang membawa js/views/timesheet.js ke dalam '
            .'cangkang. Perangkat yang sudah memegang cangkang lama tidak akan pernah mengambilnya.',
        );
    }

    public function test_the_hr_wide_screen_is_gated_and_the_personal_one_deliberately_is_not(): void
    {
        $app = $this->code(self::APP);

        $hrRoute = $this->routeBody($app, 'timesheet');
        $mineRoute = $this->routeBody($app, 'timesheet-saya');

        $this->assertStringContainsString(
            "session.can('hr.view')",
            $hrRoute,
            'Daftar satu periode memuat jam datang dan jam pulang SETIAP orang — gerbang yang sama '
            .'dengan register absensi sejak F-4.',
        );
        $this->assertStringNotContainsString(
            'session.can(',
            $mineRoute,
            '"Timesheet Saya" tidak boleh menuntut izin hr.*: orang yang paling membutuhkannya — '
            .'tukang, teknisi, pengemudi — tidak memegang satu pun. Pintunya sendiri (hr/timesheet/me) '
            .'secara struktur tidak bisa memulangkan baris orang lain.',
        );
    }

    public function test_the_empty_state_says_the_register_is_empty_and_never_draws_a_table_of_zeroes(): void
    {
        $code = $this->code(self::VIEW);

        $this->assertStringContainsString('emptyState(', $code, 'Tidak ada keadaan kosong sama sekali.');
        $this->assertStringContainsString('Register bulan ini kosong', $code);
        $this->assertStringContainsString(
            'bukan berarti tidak ada yang masuk kerja',
            $code,
            'Nol baris BUKAN "semua orang bekerja nol jam". Kalimat yang tidak mengatakannya akan '
            .'mendorong HR membuat rekap nol untuk seluruh karyawan.',
        );

        foreach (['0 jam', "'0j'", '0 menit'] as $lie) {
            $this->assertStringNotContainsString(
                $lie,
                $code,
                "Layar menuliskan «{$lie}» sebagai teks tetap. Angka nol yang dikarang klien untuk "
                .'hari yang tidak pernah diukur adalah tuduhan terhadap orang yang namanya ada di '
                .'sebelahnya.',
            );
        }
    }

    public function test_the_two_honest_limits_are_printed_above_the_numbers(): void
    {
        $code = $this->code(self::VIEW);

        $this->assertStringContainsString(
            'BELUM dibangun',
            $code,
            'Tarif akhir pekan/hari libur Kepmenaker tidak dibangun paket ini, dan layar yang '
            .'menampilkan jam lembur tanpa menyebutnya akan dibaca sebagai "inilah seluruh lembur '
            .'bulan ini".',
        );
        $this->assertStringContainsString('tidak punya kalender hari libur nasional', $code);
        $this->assertStringContainsString(
            'holidays_known',
            $code,
            'Kalimat kalender libur harus bergantung pada jawaban SERVER, bukan pada sebuah konstanta '
            .'di layar yang akan tetap berbunyi begitu setelah kalendernya dibangun.',
        );
    }

    public function test_the_screen_never_claims_to_decide_who_wins_between_ilb_and_the_derivation(): void
    {
        $code = $this->code(self::VIEW);

        $this->assertStringContainsString(
            'ILB (Izin Lembur) tetap otoritatif',
            $code,
            'Selisih yang ditampilkan tanpa menyebut siapa yang otoritatif akan dibaca sebagai '
            .'koreksi terhadap ILB.',
        );

        foreach (['seharusnya', 'yang benar adalah', 'ILB salah'] as $verdict) {
            $this->assertStringNotContainsString(
                $verdict,
                $code,
                "Layar memutuskan siapa yang menang lewat kata «{$verdict}». Yang boleh dikatakannya "
                .'hanya berapa selisihnya dan ke arah mana.',
            );
        }
    }

    public function test_the_csv_export_writes_empty_cells_and_never_a_zero_for_what_was_not_measured(): void
    {
        $code = $this->code(self::VIEW);

        $this->assertStringContainsString('toCsv(', $code);
        $this->assertMatchesRegularExpression(
            "~if \\(value === null \\|\\| value === undefined\\) return '';~",
            $code,
            'Ekspor harus menuliskan sel KOSONG untuk yang tidak diukur. Sebuah 0 di kolom "Menit '
            .'kerja" akan dibuka di Excel oleh orang yang tidak pernah melihat layar ini.',
        );
    }

    public function test_the_screen_writes_nothing_at_all(): void
    {
        $code = $this->code(self::VIEW);

        foreach (['api.post(', 'api.put(', 'api.patch(', 'api.del('] as $write) {
            $this->assertStringNotContainsString(
                $write,
                $code,
                "Layar timesheet memanggil {$write}. Ia turunan dan pembanding: yang menulis rekap "
                .'bulanan tetap manusia lewat formulir yang sudah ada, dan ILB tetap otoritatif.',
            );
        }
    }

    public function test_the_posted_period_is_announced_before_anyone_tries_to_apply_anything(): void
    {
        $code = $this->code(self::VIEW);

        $this->assertStringContainsString('payroll_posted', $code);
        $this->assertStringContainsString('sudah disetujui atau ditutup', $code);
    }

    /** Badan satu blok route('<name>', () => { … }) di app.js. */
    private function routeBody(string $app, string $name): string
    {
        $start = strpos($app, "route('{$name}'");
        $this->assertNotFalse($start, "Rute '{$name}' tidak ada di app.js.");

        $end = strpos($app, "\n  });", $start);
        $this->assertNotFalse($end, "Rute '{$name}' tidak punya akhir blok yang bisa dibaca.");

        return substr($app, $start, $end - $start);
    }
}
