<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7: RKK — Rencana Keselamatan Konstruksi yang dilampirkan pada penawaran
 * (struktur Permen PUPR 10/2021, elemen SMKK). Cetak F/RKK.
 *
 * DI CRM, bukan di Projects, karena YANG INI adalah RKK PENAWARAN: dokumen
 * yang disusun untuk memenangkan lelang, sebelum ada proyek. RKK Pelaksanaan —
 * yang hidup setelah kontrak — akan menggantung pada proyeknya bila pemilik
 * memintanya; menyatukan keduanya di satu tabel akan membuat satu baris
 * berpindah arti di tengah hidupnya.
 *
 * EMPAT BAGIAN, TIGA BENTUK BERBEDA:
 *   kebijakan  → policy (teks yang ditulis penyusunnya)
 *   IBPRP      → BARIS TAUTAN ke prj_risk_register (000391) — bukan salinan
 *   program    → program (teks)
 *   biaya SMKK → BARIS TAUTAN ke est_boq_items (000392) — nilainya TURUNAN
 *
 * project_id lintas modul TANPA constraint: register IBPRP milik Projects, dan
 * Crm tidak punya panah ke Projects di ARCHITECTURE.md. RkkService membacanya
 * MENTAH di balik Schema::hasTable — perangkat yang sama yang dipakai
 * BastPrerequisiteService pada qc_ncr, dan untuk alasan yang sama.
 * boq_id lintas modul juga tanpa constraint, meski Crm → Estimation adalah
 * panah yang sah: aturan FK CONVENTIONS §3 berlaku pada setiap batas modul,
 * legal atau tidaknya panah kodenya.
 *
 * BUKAN Approvable: lihat migrasi 000386 — maker-checker satu pengajuan lelang
 * hidup pada penawarannya, sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_rkk_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('tender_package_id')->constrained('crm_tender_packages')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();  // prj_projects — sumber baris IBPRP
            $table->unsignedBigInteger('boq_id')->nullable();      // est_boqs — asal baris biaya SMKK
            $table->string('title', 250);
            $table->text('policy')->nullable();   // kebijakan keselamatan konstruksi
            $table->text('program')->nullable();  // sasaran & program keselamatan konstruksi
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); // users — indexed, no FK
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('boq_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_rkk_documents');
    }
};
