<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_daily_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('project_id')->constrained('prj_projects')->cascadeOnDelete();
            $table->date('report_date');
            $table->string('weather_am', 20)->nullable(); // cerah / mendung / hujan
            $table->string('weather_pm', 20)->nullable();
            $table->unsignedInteger('manpower_count')->default(0);
            $table->text('activities');
            $table->text('obstacles')->nullable();
            $table->text('safety_notes')->nullable();
            $table->json('photos')->nullable();
            // User semantics (users.id) — app-owned, no DB constraint.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'report_date']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_daily_reports');
    }
};
