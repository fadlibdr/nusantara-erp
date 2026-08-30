<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6: register IBPRP per proyek (Identifikasi Bahaya, Penilaian Risiko dan
 * Pengendalian — Permen PUPR 10/2021, elemen SMKK), cetak F/IBPRP.
 *
 * BARIS REGISTER, BUKAN DOKUMEN: tanpa kode dokumen, tanpa siklus persetujuan
 * — register dicetak SATU LEMBAR PER PROYEK (F/IBPRP), dan barisnya
 * diidentifikasi oleh urutan pada lembar itu, seperti baris register variasi
 * kontrak (000732).
 *
 * SKOR ADALAH ARITMETIKA. initial_score = likelihood × severity dan
 * residual_score = residual_likelihood × residual_severity DIHITUNG
 * RiskRegisterService; klaim skor dari klien dibuang. Kolomnya tetap disimpan
 * (bukan dihitung saat baca) supaya lembar yang pernah dicetak dan barisnya
 * di-query membaca angka yang sama — tetapi satu-satunya penulis adalah
 * aritmetika service. Banding tingkat risiko (kecil/sedang/besar) TIDAK
 * disimpan: diturunkan dari skor lewat RiskLevel::fromScore, satu tempat.
 *
 * Risiko sisa nullable BERPASANGAN: baris yang pengendaliannya belum dinilai
 * ulang menyimpan NULL — bukan nol — dan lembarnya menggarisi sel itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_risk_register', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('prj_projects');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('activity', 200);        // uraian pekerjaan/aktivitas
            $table->string('hazard', 300);          // identifikasi bahaya
            $table->string('impact', 300)->nullable(); // jenis bahaya / dampak (tipe kecelakaan)
            $table->unsignedTinyInteger('likelihood');  // kemungkinan (F), 1–5
            $table->unsignedTinyInteger('severity');    // keparahan (A), 1–5
            $table->unsignedTinyInteger('initial_score'); // = L×S, ditulis service
            $table->string('controls', 500)->nullable();  // pengendalian
            $table->unsignedTinyInteger('residual_likelihood')->nullable();
            $table->unsignedTinyInteger('residual_severity')->nullable();
            $table->unsignedTinyInteger('residual_score')->nullable(); // = L'×S', ditulis service
            $table->unsignedBigInteger('created_by')->nullable(); // users — indexed, no FK
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_risk_register');
    }
};
