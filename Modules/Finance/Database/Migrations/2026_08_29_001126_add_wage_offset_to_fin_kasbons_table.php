<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 — fin_kasbons.wage_offset_total: bagian kasbon yang sudah dipulihkan
 * lewat POTONGAN UPAH MANDOR (tagihan opname SP3 mengkredit 1-1370 alih-alih
 * membayar penuh), dicatat oleh KasbonService::offsetAgainstWageBill saat
 * tagihan AP-nya disetujui dan dikembalikan oleh releaseWageOffset saat
 * tagihan itu dibatalkan.
 *
 * Sisa kasbon = amount - wage_offset_total. settle() (pertanggungjawaban
 * kuitansi) menjadi sadar-offset: ia mengkredit 1-1370 hanya sebesar sisa
 * itu — bagian yang sudah dipulihkan lewat upah telah dikredit oleh jurnal
 * tagihannya sendiri, dan mengkredit uang muka penuh dua kali membuat
 * 1-1370 bersaldo kredit atas piutang yang sudah lunas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_kasbons', function (Blueprint $table): void {
            $table->decimal('wage_offset_total', 18, 2)->default(0)->after('cash_returned');
        });
    }

    public function down(): void
    {
        Schema::table('fin_kasbons', function (Blueprint $table): void {
            $table->dropColumn('wage_offset_total');
        });
    }
};
