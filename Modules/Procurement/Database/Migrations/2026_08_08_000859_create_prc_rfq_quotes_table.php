<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu sel tabulasi banding: harga vendor X untuk baris Y, plus penanda
     * pemenang. Unik per (baris, vendor) — mengetik ulang memperbarui sel,
     * tidak menumpuk riwayat; lembar banding adalah dokumen kerja, bukan
     * jurnal.
     */
    public function up(): void
    {
        Schema::create('prc_rfq_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_item_id')->constrained('prc_rfq_items')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('prc_vendors');
            $table->decimal('unit_price', 18, 2);
            $table->boolean('is_winner')->default(false);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['rfq_item_id', 'vendor_id']);
            $table->index('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_rfq_quotes');
    }
};
