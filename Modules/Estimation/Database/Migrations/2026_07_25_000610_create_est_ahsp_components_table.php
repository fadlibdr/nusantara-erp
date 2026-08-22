<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('est_ahsp_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ahsp_id')->constrained('est_ahsp')->cascadeOnDelete();
            $table->string('component_type', 20); // labor | material | equipment
            $table->string('name', 255);
            // Cross-module link to inv_items when the material is a stocked item.
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('unit', 20);
            $table->decimal('coefficient', 15, 6);
            $table->decimal('unit_price', 18, 2);
            $table->timestamps();

            $table->index('item_id');
            $table->index('component_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('est_ahsp_components');
    }
};
