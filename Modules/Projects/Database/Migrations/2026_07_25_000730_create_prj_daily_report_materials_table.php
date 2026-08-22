<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_daily_report_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_report_id')->constrained('prj_daily_reports')->cascadeOnDelete();
            // Cross-module reference (Inventory) — indexed, no DB constraint.
            $table->unsignedBigInteger('item_id');
            $table->decimal('qty_used', 15, 3);
            $table->string('unit', 20);
            $table->timestamps();

            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_daily_report_materials');
    }
};
