<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scm_progress_claim_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('progress_claim_id')->constrained('scm_progress_claims')->cascadeOnDelete();
            $table->foreignId('subcontract_item_id')->constrained('scm_subcontract_items');
            $table->decimal('prev_progress_pct', 8, 4); // cumulative before this opname
            $table->decimal('current_progress_pct', 8, 4); // cumulative after this opname
            $table->decimal('period_progress_pct', 8, 4); // current - prev
            $table->decimal('amount', 18, 2); // period_progress_pct / 100 * item amount
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scm_progress_claim_items');
    }
};
