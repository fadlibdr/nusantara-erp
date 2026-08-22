<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Cross-module reference to hr_employees.id — no DB constraint by convention.
            $table->unsignedBigInteger('employee_id')->nullable()->after('password');
            $table->boolean('is_active')->default(true)->after('employee_id');

            $table->index('employee_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['employee_id']);
            $table->dropColumn(['employee_id', 'is_active']);
        });
    }
};
