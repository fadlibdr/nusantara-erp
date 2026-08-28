<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0-A: jam kerja dan kunci serah terima pada laporan harian.
 *
 * - work_start/work_end: jam kerja FM-10-12 yang selama ini garis kosong.
 * - lost_hours_reason: alasan jam hilang (hujan, antrian mixer, ...).
 * - locked_at: diisi saat BAST I proyek DISETUJUI untuk laporan bertanggal
 *   ≤ tanggal serah terima — pekerjaan yang diserahterimakan dan
 *   ditandatangani tiga pihak adalah riwayat beku, bukan draf. (Kelak juga
 *   diisi oleh keputusan eksternal pertama bila patch spike
 *   ExternalApprovalService hadir; lihat DailyReportService.)
 *
 * Forward-only: tidak ada baris lama yang ditulis migrasi ini. Laporan lama
 * tetap membawa nilai NULL dan tercetak persis seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prj_daily_reports', function (Blueprint $table): void {
            $table->time('work_start')->nullable()->after('weather_pm');
            $table->time('work_end')->nullable()->after('work_start');
            $table->string('lost_hours_reason', 300)->nullable()->after('work_end');
            $table->dateTime('locked_at')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('prj_daily_reports', function (Blueprint $table): void {
            $table->dropColumn(['work_start', 'work_end', 'lost_hours_reason', 'locked_at']);
        });
    }
};
