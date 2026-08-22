<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_wbs_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('prj_projects')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('prj_wbs_tasks')->nullOnDelete();
            // Cross-module reference (Estimation) — indexed, no DB constraint.
            $table->unsignedBigInteger('boq_item_id')->nullable();
            $table->string('wbs_code', 20);
            $table->string('name', 500);
            // Leaf weights sum to 100 per project; parent weight = sum of its children.
            $table->decimal('weight_pct', 8, 4)->default(0);
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
            $table->decimal('progress_pct', 8, 4)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('boq_item_id');
            $table->index(['project_id', 'wbs_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_wbs_tasks');
    }
};
