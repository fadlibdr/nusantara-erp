<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P2 — Berita Acara Negosiasi (BAN, DAN 31).
     *
     * Menutup deviasi 3.5 "BA Klarifikasi & Negosiasi": selama ini negosiasi
     * harga hanya diakui sebagai flag konfirmasi di PriceDeviationService —
     * pengakuan, bukan risalah. BAN adalah risalahnya: siapa hadir, dan harga
     * awal → harga nego per baris. Kriteria #4 membacanya: award yang nilainya
     * berbeda dari penawaran terakhir WAJIB punya BAN.
     */
    public function up(): void
    {
        Schema::create('prc_negotiation_minutes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // BAN/{Y}/{RM}/{N4}
            $table->foreignId('rfq_id')->constrained('prc_rfqs')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('prc_vendors');
            $table->date('meeting_date');
            $table->string('location', 200)->nullable();
            // Peserta: [{nama, jabatan, pihak}] — pihak = kontraktor / vendor.
            $table->json('peserta')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('rfq_id');
            $table->index('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_negotiation_minutes');
    }
};
