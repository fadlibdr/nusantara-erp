<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-5 — DENGAN DASAR APA SLIP INI DIBAYAR, dibekukan pada slipnya.
 *
 * Sampai hari ini payroll membayar lembur dengan 1,5x RATA atas total bulanan,
 * dan komentarnya sendiri di PayrollService mengakui itu kurang: Kepmenaker
 * 102/2004 memberi 1,5x untuk jam PERTAMA setiap hari lembur dan 2x untuk jam
 * berikutnya, sehingga tarif rata membayar kurang mulai jam kedua. F-5
 * memberikan rincian hariannya, jadi mulai sekarang ada DUA jalur bayar yang
 * sah — dan sebuah slip yang tidak mengatakan jalur mana yang dipakainya
 * membuat dua periode dibayar berbeda tanpa ada yang bisa melihat sebabnya.
 *
 * DUA KOLOM, DAN KENAPA DI SINI DAN BUKAN DI TABEL TURUNAN
 * -------------------------------------------------------
 * Rincian harian itu sendiri TIDAK disimpan di mana pun: ia dihitung dari
 * `hr_attendances.check_in_at`/`check_out_at` setiap kali ditanya (alasan
 * lengkapnya di kepala TimesheetService — cap jam boleh dikoreksi kapan saja,
 * dan aturannya setelan yang boleh berubah). Yang TIDAK BOLEH berubah adalah
 * uang yang sudah dibayarkan, dan tempat payroll sudah membekukan segalanya
 * adalah slip: `project_id`, `has_tax_id` dan `ter_rate` semuanya dibekukan di
 * sini dengan alasan yang sama persis.
 *
 *  - `overtime_basis`  jalur yang dipakai: 'rincian_harian' (1,5x/2x per hari),
 *                      'rata_jam_pertama' (jalur lama), atau 'tanpa_lembur'.
 *  - `overtime_rate_detail`  angka yang menghasilkan `overtime_pay`: tarif yang
 *                      berlaku saat itu, pembagi, jam pada tiap tarif, hari-hari
 *                      lemburnya, dan — ketika jalur lama yang dipakai — SEBAB
 *                      dalam satu kalimat Bahasa Indonesia.
 *
 * NULL berarti "slip ini dihitung sebelum 14 Sep 2026", dan itu keadaan yang
 * berbeda dari 'tanpa_lembur'. Tidak ada backfill dan tidak akan ada: menebak
 * dasar sebuah slip yang sudah diposting adalah mengarang bukti tentang uang
 * yang sudah keluar. Enam belas slip produksi tetap kosong, dan layar
 * mengatakannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->string('overtime_basis', 30)->nullable();
            $table->json('overtime_rate_detail')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->dropColumn(['overtime_basis', 'overtime_rate_detail']);
        });
    }
};
