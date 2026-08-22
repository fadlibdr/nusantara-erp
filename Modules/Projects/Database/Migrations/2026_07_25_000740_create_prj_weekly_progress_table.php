<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_weekly_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('prj_projects')->cascadeOnDelete();
            $table->unsignedInteger('week_no');
            $table->date('period_start');
            $table->date('period_end');
            // Cumulative percentages (kurva-S points).
            $table->decimal('planned_pct', 8, 4);
            $table->decimal('actual_pct', 8, 4);
            $table->decimal('deviation_pct', 8, 4)->default(0); // actual - planned
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'week_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_weekly_progress');
    }
};
