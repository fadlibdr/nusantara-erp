<?php

namespace Tests\Feature\Assets;

use Tests\ErpTestCase;

/**
 * F-7 — separuh peramban dari paket ini, dipaku dengan grep, karena tidak ada
 * runtime JS di host ini dan grep membaca berkas yang sama dengan yang dibaca
 * peninjau (pola TenderSpaWiringTest / CrossModuleSpaWiringTest).
 *
 * YANG DIJAGA DI SINI adalah cacat yang berulang di kampanye ini: aturan yang
 * benar di server lalu bocor di layar. Server sudah menolak menyembunyikan
 * pemicu kedua, sudah menolak menggambar "tidak terukur" sebagai nol, dan
 * sudah mengirim satuannya; ketiga hal itu bisa hilang lagi dalam satu
 * suntingan JS yang tidak dilihat satu uji PHP pun.
 *
 * Grep ini TIDAK menggantikan pemuatan di peramban (pelajaran P1-I: rilis SPA
 * belum terverifikasi sampai aplikasinya dimuat di Chromium sungguhan); ia
 * menjaga agar yang sudah dimuat itu tidak diam-diam dicabut kembali.
 */
class MaintenanceHourMeterSpaWiringTest extends ErpTestCase
{
    private function file(string $relative): string
    {
        $path = public_path('app/js/'.$relative);

        $this->assertFileExists($path, "public/app/js/{$relative} hilang.");

        return (string) file_get_contents($path);
    }

    /** Daftar perawatan membawa KEDUA pemicu, dengan judul yang berbeda. */
    public function test_the_maintenance_list_carries_both_triggers_under_distinct_headings(): void
    {
        $schema = $this->file('schema.js');

        $this->assertStringContainsString("{ key: 'next_due_date', label: 'Jadwal berikut'", $schema);
        $this->assertStringContainsString("{ key: 'next_due_hour_meter', label: 'Jam berikut'", $schema);
        // Satuannya ikut ke selnya: sebuah angka jam yang berdiri di sebelah
        // kolom tanggal tanpa satuan adalah angka yang harus ditebak.
        $this->assertStringContainsString("unit: 'jam'", $schema);
    }

    /** …dan formulirnya bisa mengisi jam TANPA tanggal. */
    public function test_the_maintenance_form_offers_the_hour_target(): void
    {
        $schema = $this->file('schema.js');

        $this->assertStringContainsString("label: 'Jadwal berikutnya (hour meter)'", $schema);
        $this->assertStringContainsString('bukan selisih jam', $schema);
    }

    /**
     * Kartu aset menampilkan keadaan jam DAN pemicu tanggal di kartu yang
     * sama — perangkap C: tidak ada layar yang boleh menyiratkan hanya satu
     * pemicu yang berlaku.
     */
    public function test_the_asset_card_shows_the_hour_state_beside_the_date_trigger(): void
    {
        $custom = $this->file('views/custom.js');

        $this->assertStringContainsString('data.hour_meter_due', $custom);
        $this->assertStringContainsString("el('.label', { text: 'Pemicu tanggal' })", $custom);
        // Tiga sebab tidak-terukur, tiga kalimat — bukan satu "—" untuk
        // ketiganya (perangkap A).
        $this->assertStringContainsString('belum_dimobilisasi:', $custom);
        $this->assertStringContainsString('tanpa_log:', $custom);
        $this->assertStringContainsString('log_tanpa_jam:', $custom);
        // Meter yang turun dikatakan, bukan disembunyikan (perangkap B).
        $this->assertStringContainsString('due.meter_went_backwards', $custom);
    }

    /**
     * Layar Ambang memformat menurut SATUAN entrinya.
     *
     * Sebelum F-7 layar ini memanggil fmt.rupiah pada kedua sel angkanya tanpa
     * melihat `unit` yang setiap entri sudah deklarasikan sejak F-2 — jadi
     * entri berjam pertama akan mencetak "Rp 3.375,50" untuk 3.375,5 JAM.
     */
    public function test_the_threshold_screen_formats_by_the_entry_unit(): void
    {
        $ambang = $this->file('views/ambang.js');

        $this->assertStringContainsString("unit === 'rupiah' ? fmt.rupiah(value) : fmt.qty(value, unit)", $ambang);
        $this->assertStringContainsString('selUkuran(row.actual, measure.unit)', $ambang);
        $this->assertStringContainsString('selUkuran(row.limit, measure.unit)', $ambang);
        // Sel rupiah lama sudah tidak ada lagi — kalau ia kembali, satu entri
        // berjam akan kembali mencetak rupiah tanpa satu uji pun merah.
        $this->assertStringNotContainsString('selRupiah(', $ambang);
    }

    /**
     * …dan entri yang tidak proporsional mencetak SISA, bukan persentase, dan
     * lencana ambangnya berbunyi dalam satuannya.
     */
    public function test_the_threshold_screen_prints_remaining_hours_not_a_percentage(): void
    {
        $ambang = $this->file('views/ambang.js');

        $this->assertStringContainsString("measure.proportional === false ? 'Sisa' : 'Terpakai'", $ambang);
        $this->assertStringContainsString('selSisa(row, measure.unit)', $ambang);
        /*
         * LENCANANYA DIPAKU PADA KODENYA, BUKAN PADA KATANYA.
         *
         * 'sebelum batas' muncul DUA kali di berkas itu — sekali di komentar
         * di atas cabangnya, sekali di kodenya — jadi asersi lama hijau meski
         * seluruh ternary lencananya diganti. Terukur (verifikasi F-7):
         * mengganti cabang itu dengan `Peringatan ≥ ${fmt.percent(...)}`
         * sambil membiarkan komentarnya -> OK (6 uji), dan yang terbaca di
         * Chromium adalah "Servis alat menurut jam operasi / Peringatan ≥ —"
         * tepat di atas tabel berisi jam (warn_pct null untuk entri jam).
         */
        $this->assertStringContainsString('measure.warn_margin === null', $ambang);
        $this->assertStringContainsString('fmt.qty(measure.warn_margin, measure.unit)', $ambang);
    }

    /**
     * LAYAR DETAIL SATU PERAWATAN MENGEJA JAMNYA SEPERTI PERMUKAAN LAIN.
     *
     * detail.js memilih pemformat menurut NAMA KUNCI, berurutan: PERCENT_KEY,
     * /_hours$/, MONEY_KEY, DATE_KEY — lalu String(value). 'next_due_hour_meter'
     * berakhiran '_meter' dan tidak cocok satu pun, jadi ia mendarat di
     * fallback: panel Informasi berbunyi "Jadwal berikutnya (hour meter)
     * 5212.5" — titik desimal Inggris, tanpa pemisah ribuan, tanpa satuan —
     * satu baris di bawah "Jatuh tempo berikutnya 14 Des 2026", untuk angka
     * yang di daftar, di kartu aset, dan di cetakan berbunyi "5.212,5 jam".
     * Cabang /_hours$/ ada persis untuk mencegah hal ini pada total_hours;
     * kunci berjam berikutnya luput darinya karena namanya berakhir lain.
     */
    public function test_the_detail_screen_prints_an_hour_meter_key_in_hours(): void
    {
        $detail = $this->file('views/detail.js');

        $this->assertStringContainsString("next_due_hour_meter: 'Jadwal berikutnya (hour meter)'", $detail);
        // Potongan KODE-nya, bukan kata-kata di sekitarnya: sebuah asersi
        // yang bisa dipenuhi komentar tidak menjaga apa pun (pelajaran F-6).
        $this->assertStringContainsString('/hour_meter$/.test(key)', $detail);
        $this->assertStringContainsString("fmt.qty(value, 'jam')", $detail);
    }

    /**
     * Sel 'qty' meneruskan `unit` — satu baris yang membuat satuan sebuah
     * kolom sampai ke layar. Kolom lama tidak mendeklarasikan unit, jadi
     * bentuknya tidak berubah.
     */
    public function test_the_qty_cell_carries_an_optional_unit(): void
    {
        $cells = $this->file('cells.js');

        $this->assertStringContainsString('fmt.qty(raw, column.unit)', $cells);
    }
}
