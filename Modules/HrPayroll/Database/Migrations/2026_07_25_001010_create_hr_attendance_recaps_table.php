<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance_recaps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month'); // 1..12
            $table->unsignedTinyInteger('work_days')->default(0);
            $table->unsignedTinyInteger('present_days')->default(0);
            $table->unsignedTinyInteger('sick_days')->default(0);
            $table->unsignedTinyInteger('leave_days')->default(0);
            $table->unsignedTinyInteger('alpha_days')->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->timestamps();

            $table->unique(
                ['employee_id', 'period_year', 'period_month'],
                'hr_attendance_recaps_period_unique'
            );
            $table->index(['period_year', 'period_month'], 'hr_attendance_recaps_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_recaps');
    }
};
