<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // GRN/{Y}/{RM}/{N4}
            $table->foreignId('warehouse_id')->constrained('inv_warehouses');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->date('receipt_date');
            $table->string('delivery_note_no', 100)->nullable(); // no. surat jalan vendor
            $table->unsignedBigInteger('received_by')->nullable(); // users.id
            $table->string('status', 30)->default('draft'); // draft / posted
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('purchase_order_id');
            $table->index('vendor_id');
            $table->index('received_by');
            $table->index('status');
        });

        Schema::create('inv_goods_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('inv_goods_receipts');
            $table->foreignId('item_id')->constrained('inv_items');
            $table->unsignedBigInteger('po_item_id')->nullable(); // prc_purchase_order_items.id
            $table->decimal('qty', 15, 3);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();

            $table->index('po_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_goods_receipt_items');
        Schema::dropIfExists('inv_goods_receipts');
    }
};
