<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P2 — Keputusan Pemenang / Award Decision (AWD, DAN 4.4/4.5/4.9/32/36).
     *
     * Menutup deviasi 3.5 "BA Keputusan Pemenang / SK ... tanpa komite" dan
     * separuh kriteria #4: PO/SPK dari sebuah RFQ tidak dapat disetujui tanpa
     * award ini DISETUJUI (PoService/SubcontractService::assertAwardApproved).
     *
     * Approvable dengan AMBANG N-LEVEL (config('erp.approvals.award_decision')):
     * nilai award menentukan berapa penyetuju berbeda yang diperlukan, tingkat 2+
     * dari pemegang prc.approve-director. Kode AWD dipilih (bukan BAP) agar tidak
     * bentrok dengan BAPP sertifikat zona P3.
     */
    public function up(): void
    {
        Schema::create('prc_award_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // AWD/{Y}/{RM}/{N4}
            $table->foreignId('rfq_id')->constrained('prc_rfqs');
            $table->foreignId('vendor_id')->constrained('prc_vendors'); // pemenang

            // Nilai wajar (RAB/HPS) vs nilai yang diputuskan. deviation_amount =
            // awarded - rab; deviation_reason WAJIB bila deviation_amount > 0
            // (AwardDecisionService), karena membayar di atas RAB menuntut alasan
            // yang bisa diaudit.
            $table->decimal('rab_amount', 18, 2)->default(0);
            $table->decimal('awarded_amount', 18, 2)->default(0);
            $table->decimal('deviation_amount', 18, 2)->default(0);
            $table->string('deviation_reason', 500)->nullable();

            // Anggota komite: [{nama, jabatan}] — keputusan pemenang bukan tanda
            // tangan satu orang.
            $table->json('committee')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 30)->default('draft'); // DocumentStatus
            $table->timestamps();
            $table->softDeletes();

            $table->index('rfq_id');
            $table->index('vendor_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_award_decisions');
    }
};
