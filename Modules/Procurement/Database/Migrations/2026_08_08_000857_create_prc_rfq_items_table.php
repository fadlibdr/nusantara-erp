<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Baris RFQ — barang/jasa yang dimintakan penawaran. Disalin dari baris
     * PR (termasuk boq_item_id, supaya tautan anggaran hidup terus sampai PO)
     * atau diketik lepas untuk RFQ mandiri.
     */
    public function up(): void
    {
        Schema::create('prc_rfq_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained('prc_rfqs')->cascadeOnDelete();
            $table->unsignedInteger('line_no')->default(1);
            $table->unsignedBigInteger('item_id')->nullable();     // inv_items.id
            $table->unsignedBigInteger('boq_item_id')->nullable(); // est_boq_items.id
            $table->string('description', 500);
            $table->decimal('qty', 15, 3);
            $table->string('unit', 20)->nullable();
            $table->timestamps();

            $table->index('item_id');
            $table->index('boq_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_rfq_items');
    }
};
