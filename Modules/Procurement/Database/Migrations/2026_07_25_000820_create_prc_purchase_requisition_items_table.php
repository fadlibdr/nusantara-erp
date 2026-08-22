<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prc_purchase_requisition_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_requisition_id')
                ->constrained('prc_purchase_requisitions')
                ->cascadeOnDelete();
            $table->unsignedInteger('line_no')->default(1);
            $table->unsignedBigInteger('item_id')->nullable(); // inv_items.id (null for non-stock/service lines)
            $table->string('description', 500)->nullable();    // override / free text when no item
            $table->decimal('qty', 15, 3);
            $table->string('unit', 20)->nullable();
            $table->decimal('estimated_price', 18, 2)->default(0);
            $table->unsignedBigInteger('boq_item_id')->nullable(); // est_boq_items.id, budget linkage
            $table->timestamps();

            $table->index('item_id');
            $table->index('boq_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_purchase_requisition_items');
    }
};
