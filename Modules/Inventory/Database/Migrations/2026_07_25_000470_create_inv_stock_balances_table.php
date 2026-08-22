<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('inv_warehouses');
            $table->foreignId('item_id')->constrained('inv_items');
            $table->decimal('qty', 15, 3)->default(0);
            $table->decimal('avg_cost', 18, 2)->default(0); // per-warehouse moving average
            $table->timestamps();

            $table->unique(['warehouse_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_balances');
    }
};
