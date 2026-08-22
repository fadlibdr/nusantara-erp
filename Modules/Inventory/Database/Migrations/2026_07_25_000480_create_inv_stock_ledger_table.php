<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('warehouse_id')->constrained('inv_warehouses');
            $table->date('trx_date');
            $table->string('reference_type', 150); // morph-style: source document class
            $table->unsignedBigInteger('reference_id');
            $table->string('direction', 10); // in / out
            $table->decimal('qty', 15, 3);
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('balance_qty_after', 15, 3);
            $table->dateTime('created_at')->nullable(); // append-only: no updated_at

            $table->index(['item_id', 'warehouse_id', 'trx_date']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_ledger');
    }
};
