<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_manpower_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('prj_projects')->cascadeOnDelete();
            // Cross-module reference (hr_employees) — indexed, no DB constraint.
            $table->unsignedBigInteger('employee_id');
            $table->string('role_on_project', 100);
            $table->date('assigned_from');
            $table->date('assigned_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('employee_id');
            $table->index(['project_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_manpower_assignments');
    }
};
