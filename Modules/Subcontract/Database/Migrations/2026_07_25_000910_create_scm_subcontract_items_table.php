<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scm_subcontract_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subcontract_id')->constrained('scm_subcontracts')->cascadeOnDelete();
            $table->unsignedBigInteger('boq_item_id')->nullable(); // est_boq_items origin line
            $table->unsignedInteger('line_no')->default(0);
            $table->string('wbs_code', 20)->nullable();
            $table->string('description', 500);
            $table->decimal('qty', 15, 3);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2); // qty * unit_price
            $table->decimal('progress_pct', 8, 4)->default(0); // cumulative physical progress, bumped on claim approval
            $table->timestamps();

            $table->index('boq_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scm_subcontract_items');
    }
};
