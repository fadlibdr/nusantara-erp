<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('est_boq_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('boq_id')->constrained('est_boqs')->cascadeOnDelete();
            $table->string('section_no', 10);
            $table->string('name', 255);
            // Cached sum of the section's item amounts (BoqService::recalcTotals).
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('est_boq_sections');
    }
};
