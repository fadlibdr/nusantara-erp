<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RAP — Rencana Anggaran Pelaksanaan (internal cost budget derived from a BOQ).
        Schema::create('est_cost_budgets', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('boq_id')->constrained('est_boqs');
            // Cross-module reference (Projects) — indexed, no DB constraint.
            $table->unsignedBigInteger('project_id')->nullable();
            $table->decimal('target_margin_pct', 8, 4);
            $table->decimal('total_budget', 18, 2)->default(0);
            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('est_cost_budgets');
    }
};
