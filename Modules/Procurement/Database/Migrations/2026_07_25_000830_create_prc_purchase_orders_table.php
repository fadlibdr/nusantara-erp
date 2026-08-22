<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prc_purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // PO/{Y}/{RM}/{N4}
            $table->foreignId('vendor_id')->constrained('prc_vendors');
            $table->foreignId('purchase_requisition_id')
                ->nullable()
                ->constrained('prc_purchase_requisitions');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable(); // deliver-to warehouse
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->unsignedInteger('payment_term_days')->default(30);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('dpp', 18, 2)->default(0);
            $table->decimal('ppn_rate', 8, 4)->default(0); // 0 when the vendor is non-PKP
            $table->decimal('ppn_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->boolean('needs_director_approval')->default(false); // computed on submit vs config threshold
            $table->text('delivery_address')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('warehouse_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_purchase_orders');
    }
};
