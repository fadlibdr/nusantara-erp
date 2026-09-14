<?php

namespace Modules\HrPayroll\Enums;

/**
 * LIMA KEADAAN SATU HARI, dan tidak satu pun boleh runtuh menjadi "0 jam".
 *
 * Produksi hari ini (14 Sep 2026) memegang NOL baris `hr_attendances`: F-4
 * di-deploy 8 September dan belum ada satu orang pun yang menekan tombolnya.
 * Artinya setiap layar paket ini pertama kali dilihat orang DALAM KEADAAN
 * KOSONG — dan sebuah keadaan kosong yang berbunyi "0 jam" menyatakan sebuah
 * pengukuran yang tidak pernah terjadi. Itu bukan kehalusan tampilan: angka 0
 * jam kerja di sebelah nama orang adalah tuduhan.
 *
 * Karena itu keadaan hari adalah enum, bukan sebuah `if ($minutes > 0)`:
 *
 *  - Terukur         dua cap jam, dan pulang sesudah masuk. Satu-satunya
 *                    keadaan yang boleh membawa angka jam. Lemburnya BOLEH
 *                    nol, dan nol itu TERUKUR: harinya diukur, lemburnya
 *                    memang tidak ada.
 *  - SetengahTerukur satu cap jam saja — hampir selalu "lupa absen pulang",
 *                    dan itu akan sering terjadi. Jam kerjanya BUKAN nol dan
 *                    lemburnya BUKAN nol: keduanya BELUM TERUKUR, dan hari
 *                    seperti ini justru yang harus terlihat HR untuk dikoreksi
 *                    lewat pintu koreksi F-4. Sepasang cap jam yang pulangnya
 *                    tidak sesudah masuknya juga masuk ke sini: dua stempel
 *                    yang tidak membentuk rentang tidak mengukur apa pun.
 *  - TidakTercatat   hari kerja tanpa cap jam sama sekali. Baris kerani yang
 *                    menuliskan "hadir" tanpa jam TETAP masuk ke sini —
 *                    kehadirannya tercatat, jamnya tidak — dan keterangan
 *                    kerani itu dibawa terpisah di `attendance_status` supaya
 *                    layar bisa mengatakan keduanya sekaligus.
 *  - NonKerja        hari yang memang bukan hari kerja menurut pola pekan
 *                    (hr.leave.workweek_days). Tidak ada yang hilang di sini,
 *                    jadi tidak ada yang perlu dikoreksi.
 *  - BelumTiba       tanggal yang BELUM TERJADI (putaran penutup, V-2). Layar
 *                    ini dibuka pada bulan BERJALAN, jadi tanpa keadaan ini
 *                    seluruh sisa bulan terhitung "tidak tercatat": pada 3 Juni
 *                    seorang yang bercap jam lengkap setiap hari kerja yang
 *                    sudah lewat dituduh melewatkan 23 hari, dan kolom "Hari
 *                    tanpa cap jam" berbunyi 23 untuk semua orang sepanjang
 *                    bulan berjalan. Menuduh orang atas hari yang belum tiba
 *                    adalah bentuk yang sama dengan "0 jam" pada hari yang
 *                    tidak diukur — angka yang menyatakan sesuatu yang tidak
 *                    pernah terjadi.
 *
 * Hari non-kerja yang TERNYATA ada cap jamnya tidak menjadi keadaan kelima: ia
 * Terukur dengan penanda `non_working_day`, dan lemburnya sengaja TIDAK
 * diusulkan — tarif akhir pekan/hari libur Kepmenaker (2x/3x/4x) tidak
 * dibangun paket ini, dan memakai tarif hari kerja untuknya berarti membayar
 * kurang tanpa ada yang bisa melihat sebabnya.
 */
enum TimesheetDayState: string
{
    case Terukur = 'terukur';
    case SetengahTerukur = 'setengah_terukur';
    case TidakTercatat = 'tidak_tercatat';
    case NonKerja = 'non_kerja';
    case BelumTiba = 'belum_tiba';

    public function label(): string
    {
        return match ($this) {
            self::Terukur => 'Terukur',
            self::SetengahTerukur => 'Setengah terukur',
            self::TidakTercatat => 'Tidak tercatat',
            self::NonKerja => 'Hari non-kerja',
            self::BelumTiba => 'Belum tiba',
        };
    }

    /** Satu-satunya keadaan yang boleh membawa angka jam kerja dan jam lembur. */
    public function carriesHours(): bool
    {
        return $this === self::Terukur;
    }
}
