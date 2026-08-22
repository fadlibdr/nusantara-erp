<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_items', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // ITM-nnnn
            $table->string('name');
            $table->foreignId('category_id')->constrained('inv_item_categories');
            $table->string('unit', 20); // zak / btg / m3 / roll / unit / ls
            $table->string('barcode', 100)->nullable();
            $table->string('item_type', 30)->default('material');
            $table->decimal('min_stock', 15, 3)->default(0);
            $table->decimal('avg_cost', 18, 2)->default(0);   // global weighted average, system-maintained
            $table->decimal('last_price', 18, 2)->default(0); // last GRN unit cost
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('item_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_items');
    }
};
