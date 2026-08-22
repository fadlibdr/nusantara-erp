<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prc_vendor_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained('prc_vendors')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('evaluated_by')->nullable(); // users.id
            $table->string('period', 20); // e.g. "2026-S1", "2026-Q2"
            $table->unsignedTinyInteger('quality_score');  // 1-5
            $table->unsignedTinyInteger('delivery_score'); // 1-5
            $table->unsignedTinyInteger('price_score');    // 1-5
            $table->unsignedTinyInteger('service_score');  // 1-5
            $table->decimal('total_score', 4, 2)->default(0); // average of the four scores
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index('evaluated_by');
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_vendor_evaluations');
    }
};
