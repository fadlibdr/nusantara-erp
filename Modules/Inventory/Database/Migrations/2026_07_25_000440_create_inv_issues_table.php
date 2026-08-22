<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_issues', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // ISS/{Y}/{RM}/{N4}
            $table->foreignId('warehouse_id')->constrained('inv_warehouses');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('wbs_task_id')->nullable(); // prj WBS task consuming the material
            $table->date('issue_date');
            $table->unsignedBigInteger('issued_by')->nullable(); // users.id
            $table->string('purpose', 500);
            $table->string('status', 30)->default('draft'); // draft / posted
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('wbs_task_id');
            $table->index('issued_by');
            $table->index('status');
        });

        Schema::create('inv_issue_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_id')->constrained('inv_issues');
            $table->foreignId('item_id')->constrained('inv_items');
            $table->decimal('qty', 15, 3);
            $table->decimal('unit_cost', 18, 2)->default(0); // filled from warehouse avg cost at posting
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_issue_items');
        Schema::dropIfExists('inv_issues');
    }
};
