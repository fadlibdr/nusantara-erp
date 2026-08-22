<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('est_boq_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('boq_id')->constrained('est_boqs')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('est_boq_sections')->cascadeOnDelete();
            $table->string('wbs_code', 20);
            $table->string('description', 500);
            $table->foreignId('ahsp_id')->nullable()->constrained('est_ahsp')->nullOnDelete();
            $table->decimal('qty', 15, 3);
            $table->string('unit', 20);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2); // qty * unit_price
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('est_boq_items');
    }
};
