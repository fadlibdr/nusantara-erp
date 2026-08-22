<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ast_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // AST-{N4}
            $table->string('name', 150);
            $table->foreignId('category_id')->constrained('ast_categories');
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('serial_no', 100)->nullable();
            $table->date('acquisition_date');
            $table->decimal('acquisition_cost', 18, 2);
            $table->decimal('salvage_value', 18, 2)->default(0);
            $table->unsignedInteger('useful_life_months');
            $table->date('depreciation_start_date')->nullable();
            $table->decimal('accumulated_depreciation', 18, 2)->default(0);
            $table->decimal('book_value', 18, 2)->default(0); // acquisition_cost - accumulated_depreciation
            $table->unsignedBigInteger('current_project_id')->nullable(); // prj_projects.id
            $table->unsignedBigInteger('custodian_employee_id')->nullable(); // hr_employees.id
            $table->unsignedBigInteger('warehouse_id')->nullable(); // inv_warehouses.id (storage location)
            $table->string('status', 30)->default('available'); // available/deployed/maintenance/disposed
            $table->date('disposal_date')->nullable();
            $table->decimal('disposal_value', 18, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('current_project_id');
            $table->index('custodian_employee_id');
            $table->index('warehouse_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ast_assets');
    }
};
