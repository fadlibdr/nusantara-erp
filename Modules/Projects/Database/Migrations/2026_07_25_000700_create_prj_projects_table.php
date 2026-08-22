<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            // Cross-module references (Crm / Estimation / HrPayroll) — indexed, no DB constraint.
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('boq_id')->nullable();
            $table->string('type', 30);
            $table->string('location')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->decimal('contract_value', 18, 2)->default(0); // DPP, excludes PPN
            $table->decimal('retention_pct', 8, 4)->default(5);
            $table->unsignedSmallInteger('warranty_months')->default(0);
            $table->string('status', 30)->default('preparation');
            // Employee semantics (hr_employees.id) — cross-module, no DB constraint.
            $table->unsignedBigInteger('project_manager_id')->nullable();
            $table->unsignedBigInteger('site_manager_id')->nullable();
            $table->decimal('planned_progress_pct', 8, 4)->default(0);
            $table->decimal('actual_progress_pct', 8, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('contract_id');
            $table->index('customer_id');
            $table->index('boq_id');
            $table->index('project_manager_id');
            $table->index('site_manager_id');
            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_projects');
    }
};
