<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Temuan #34 tahap 3 — RFQ (lembar banding penawaran vendor).
     *
     * Tabulasi banding harga yang selama ini hidup di spreadsheet pribadi
     * staf pengadaan: undangan vendor, harga per vendor per baris, pemenang.
     * Tanpa tabel ini "sudah banding tiga vendor" tidak bisa diaudit dan
     * harga pemenang diketik ulang ke PO dengan tangan.
     */
    public function up(): void
    {
        Schema::create('prc_rfqs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // RFQ/{Y}/{RM}/{N4}
            $table->foreignId('purchase_requisition_id')->nullable()
                ->constrained('prc_purchase_requisitions');
            // Referensi lintas modul (Projects) — indeks tanpa constraint DB.
            $table->unsignedBigInteger('project_id')->nullable();
            $table->date('rfq_date');
            $table->date('due_date')->nullable(); // batas vendor memasukkan penawaran
            $table->text('notes')->nullable();
            // draft (tabulasi masih diisi) -> closed (arsip, tidak bisa diubah).
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_rfqs');
    }
};
