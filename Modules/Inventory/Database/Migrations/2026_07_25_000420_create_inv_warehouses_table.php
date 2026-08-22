<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // WH-PUSAT / WH-PRJ-...
            $table->string('name');
            $table->unsignedBigInteger('project_id')->nullable(); // set => gudang site
            $table->text('address')->nullable();
            $table->unsignedBigInteger('keeper_employee_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('keeper_employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_warehouses');
    }
};
