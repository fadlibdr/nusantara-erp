<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ast_deployments', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // DEP/{Y}/{RM}/{N3}
            $table->foreignId('asset_id')->constrained('ast_assets');
            $table->unsignedBigInteger('project_id'); // prj_projects.id
            $table->date('deployed_from');
            $table->date('planned_until')->nullable();
            $table->date('returned_at')->nullable();
            $table->decimal('daily_rate_internal', 18, 2)->nullable(); // internal equipment charge to project
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('active'); // active/returned
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ast_deployments');
    }
};
