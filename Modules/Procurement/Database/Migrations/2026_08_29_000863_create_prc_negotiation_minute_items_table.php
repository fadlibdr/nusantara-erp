<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Baris BAN: harga awal → harga nego per barang. rfq_item_id opsional
     * (tautan ke baris RFQ yang dinegosiasi) tanpa constraint cascade agar
     * baris tetap terbaca bila lembar banding-nya diarsip.
     */
    public function up(): void
    {
        Schema::create('prc_negotiation_minute_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('negotiation_minute_id')->constrained('prc_negotiation_minutes')->cascadeOnDelete();
            $table->unsignedBigInteger('rfq_item_id')->nullable(); // prc_rfq_items.id (tanpa constraint)
            $table->unsignedInteger('line_no')->default(1);
            $table->string('description', 500);
            $table->decimal('qty', 15, 3)->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('harga_awal', 18, 2)->default(0);
            $table->decimal('harga_nego', 18, 2)->default(0);
            $table->timestamps();

            $table->index('rfq_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_negotiation_minute_items');
    }
};
