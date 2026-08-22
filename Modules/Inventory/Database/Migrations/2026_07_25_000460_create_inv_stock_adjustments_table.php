<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // ADJ/{Y}/{RM}/{N4}
            $table->foreignId('warehouse_id')->constrained('inv_warehouses');
            $table->date('adjustment_date');
            $table->string('reason', 30)->default('opname'); // opname / damage / loss
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft'); // Core DocumentStatus lifecycle
            $table->dateTime('posted_at')->nullable(); // ledger hit exactly once, on approval
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('inv_stock_adjustment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('inv_stock_adjustments');
            $table->foreignId('item_id')->constrained('inv_items');
            $table->decimal('system_qty', 15, 3)->default(0);  // balance snapshot at entry time
            $table->decimal('counted_qty', 15, 3);
            $table->decimal('diff_qty', 15, 3)->default(0);    // counted - system
            $table->decimal('unit_cost', 18, 2)->default(0);   // warehouse avg cost at posting
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_adjustment_items');
        Schema::dropIfExists('inv_stock_adjustments');
    }
};
