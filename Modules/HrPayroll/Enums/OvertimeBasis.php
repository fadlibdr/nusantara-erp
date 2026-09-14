<?php

namespace Modules\HrPayroll\Enums;

/**
 * Dengan dasar apa lembur satu slip dibayar (F-5).
 *
 * Dibekukan pada slipnya (`hr_payslips.overtime_basis`, migrasi 001094), bukan
 * dihitung ulang saat layar dibuka: ia menjawab pertanyaan tentang uang yang
 * SUDAH keluar, dan jawaban yang berubah karena absensi bulan itu dikoreksi
 * sesudahnya bukan jawaban.
 *
 * NULL — bukan salah satu dari ketiganya — berarti slip dihitung sebelum
 * 14 September 2026, ketika belum ada yang mencatat dasarnya. Keadaan itu tidak
 * ditebak dan tidak diisi surut.
 */
enum OvertimeBasis: string
{
    /**
     * Kepmenaker 102/2004 Pasal 11 sebagaimana mestinya: jam PERTAMA setiap
     * hari lembur dibayar 1,5x dan jam berikutnya 2x, karena sistem tahu hari
     * mana saja dan berapa jam di tiap harinya.
     *
     * Dipakai HANYA ketika rincian harian yang terukur berjumlah SAMA PERSIS
     * dengan total rekap bulanan yang dibayar. Kalau keduanya berbeda, bentuk
     * harian yang diketahui bukan bentuk dari jam yang dibayar, dan memakainya
     * berarti membelah sebuah angka menurut bentuk angka lain.
     */
    case RincianHarian = 'rincian_harian';

    /**
     * Jalur lama, yang tetap hidup: seluruh total bulanan dibayar dengan tarif
     * jam pertama. Ia membayar KURANG untuk jam kedua dan seterusnya dalam satu
     * hari — itu sebabnya ia bukan lagi jalur utama — tetapi ia tidak mengarang
     * apa pun, dan slipnya menyebutkan sebab ia yang terpakai.
     */
    case RataJamPertama = 'rata_jam_pertama';

    /** Rekap bulan itu tidak membawa satu jam lembur pun. */
    case TanpaLembur = 'tanpa_lembur';

    public function label(): string
    {
        return match ($this) {
            self::RincianHarian => 'Rincian harian (1,5x jam pertama, 2x berikutnya)',
            self::RataJamPertama => 'Tarif jam pertama rata atas total bulanan',
            self::TanpaLembur => 'Tanpa lembur',
        };
    }
}
