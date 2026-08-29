<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P2 — tabulasi penilaian penawaran berbobot (sistem nilai DAN 4.8).
     *
     * Satu baris per (RFQ, vendor). Memperluas prc_rfq_quotes (yang menyimpan
     * HARGA per baris) dengan SKOR per aspek: harga dihitung otomatis dari rasio
     * penawaran vendor terhadap RAB, aspek lain diinput 0–100, total berbobot &
     * peringkat dihitung service. is_winner di prc_rfq_quotes tetap keputusan
     * per-baris; tabulasi ini adalah dasar berbobot yang membenarkannya.
     */
    public function up(): void
    {
        Schema::create('prc_bid_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained('prc_rfqs')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('prc_vendors');

            // Pembanding RAB (nilai wajar / HPS) dan total penawaran vendor ini
            // untuk lingkup yang dinilai. offered_amount diturunkan dari
            // prc_rfq_quotes bila tidak diisi eksplisit.
            $table->decimal('rab_amount', 18, 2)->nullable();
            $table->decimal('offered_amount', 18, 2)->nullable();

            // Skor per aspek 0–100. harga_score DIHITUNG dari rasio ke RAB
            // (BidEvaluationService::hargaScore); empat lainnya diinput.
            $table->decimal('harga_score', 6, 2)->default(0);
            $table->decimal('mutu_score', 6, 2)->default(0);
            $table->decimal('waktu_score', 6, 2)->default(0);
            $table->decimal('keuangan_score', 6, 2)->default(0);
            $table->decimal('k3_score', 6, 2)->default(0);

            // Total berbobot 0–100 dan peringkat otomatis (1 = terbaik).
            $table->decimal('weighted_score', 6, 2)->default(0);
            $table->unsignedInteger('rank')->nullable();

            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['rfq_id', 'vendor_id']);
            $table->index('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_bid_evaluations');
    }
};
