<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // TRF/{Y}/{RM}/{N4}
            $table->foreignId('from_warehouse_id')->constrained('inv_warehouses');
            $table->foreignId('to_warehouse_id')->constrained('inv_warehouses');
            $table->date('transfer_date');
            $table->string('status', 30)->default('draft'); // draft / in_transit / received
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('inv_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained('inv_transfers');
            $table->foreignId('item_id')->constrained('inv_items');
            $table->decimal('qty', 15, 3);
            $table->decimal('unit_cost', 18, 2)->default(0); // source warehouse avg cost, frozen at send
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_transfer_items');
        Schema::dropIfExists('inv_transfers');
    }
};
