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

    /**
     * Cacat yang HANYA peramban temukan (S42m, 14 Sep 2026), dipaku di sini
     * supaya gerbang PHP ikut menjaganya.
     *
     * Ubin "Hari terukur" punya DUA kalimat sampai bukti peramban dijalankan:
     * "N hari hanya satu cap jam" atau "setiap hari bercap jam lengkap". Pada
     * bulan yang tidak punya satu pun catatan, yang kedua yang terpilih — dan
     * ia berbunyi seperti PUJIAN tentang orang yang tidak pernah menekan
     * tombolnya sama sekali. Tidak satu pun uji PHP bisa melihatnya: JSON-nya
     * benar (measured_days 0, half 0), kalimatnyalah yang bohong. Cacat yang
     * sama persis pernah ditemukan F-4 pada baris kerani murni ("0 hari ·
     * semua di dalam radius").
     */
    public function test_an_empty_month_is_not_congratulated_for_clocking_in_every_day(): void
    {
        $code = $this->code(self::VIEW);

        $this->assertStringContainsString(
            'belum ada satu hari pun dengan cap jam masuk dan pulang',
            $code,
            'Ubin "Hari terukur" harus punya EMPAT kalimat: bulan yang belum punya satu pun hari '
            .'terukur, hari setengah terukur, hari yang lewat tanpa cap jam sama sekali, dan '
            .'barulah bulan yang lengkap. Kalimat yang hilang berarti sebuah bulan dipuji karena '
            .'kehadiran yang tidak pernah terjadi.',
        );
        $this->assertStringContainsString('summary.measured_days === 0', $code);

        /*
         * Lubang KETIGA, yang perbaikan pertama tinggalkan (putaran verifikasi
         * 14 Sep 2026): satu hari terukur dari 26 juga berbunyi "setiap hari
         * bercap jam lengkap", karena hari yang TIDAK TERCATAT SAMA SEKALI
         * tidak masuk half_measured_days. `unrecorded_days` sudah ada di muatan
         * sejak awal dan tidak pernah dipakai.
         *
         * Penjaga ini tetap penjaga TEKS — ia tidak menjalankan cabangnya, dan
         * itu batasnya yang jujur. Yang menjalankan cabang ini sungguhan adalah
         * harness S42m dengan muatan 1-dari-26.
         */
        $this->assertStringContainsString(
            'summary.unrecorded_days',
            $code,
            'Pujian "setiap hari bercap jam lengkap" tidak boleh menyala selama masih ada hari '
            .'kerja yang lewat tanpa satu cap jam pun. Tiga kalimat menutup bulan yang KOSONG dan '
            .'meninggalkan bulan yang HAMPIR kosong dipuji dengan kalimat yang sama.',
        );
    }

    /**
     * Istirahat DIKATAKAN, bukan dipotong diam-diam.
     *
     * Potongan yang menentukan berapa jam lembur seseorang, tetapi tidak
     * disebutkan di layar yang mencetak angkanya, adalah potongan yang tidak
     * bisa diperiksa siapa pun — dan pertanyaan "kenapa 08:00–17:00 bukan
     * sembilan jam kerja" akan diajukan pada hari pertama.
     */
    public function test_the_break_taken_out_of_the_working_hours_is_printed_on_the_screen(): void
    {
        $code = $this->code(self::VIEW);

        $this->assertStringContainsString('policy.break_minutes', $code);
        $this->assertStringContainsString('UU 13/2003 Ps. 79', $code);
        $this->assertStringContainsString(
            'TIDAK dihitung jam kerja',
            $code,
            'Kartu kebijakan harus menyebut istirahat, karena ia menentukan berapa menit yang '
            .'menjadi lembur. Tanpa kalimat ini, selisih antara cap jam di layar dan jam kerja di '
            .'sebelahnya tidak punya penjelasan di mana pun.',
        );
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
