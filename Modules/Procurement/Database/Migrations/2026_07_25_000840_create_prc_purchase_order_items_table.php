<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prc_purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')
                ->constrained('prc_purchase_orders')
                ->cascadeOnDelete();
            $table->unsignedInteger('line_no')->default(1);
            $table->unsignedBigInteger('item_id')->nullable(); // inv_items.id (null for non-stock/service lines)
            $table->string('description', 500);
            $table->decimal('qty', 15, 3);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('qty_received', 15, 3)->default(0); // incremented by goods receipts (GRN)
            $table->timestamps();

            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_purchase_order_items');
    }
};
