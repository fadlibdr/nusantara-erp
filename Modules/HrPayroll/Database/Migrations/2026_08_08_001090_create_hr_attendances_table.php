<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Absensi harian (finding #22, half 2) — a REGISTER, deliberately not a pay
 * engine. Rows record who was on which site on which day; nothing here reaches
 * a payslip. The monthly hr_attendance_recaps stays the payroll input of
 * record, so recording attendance late or fixing a typo can never move money
 * that was already paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->date('date');
            $table->string('status', 20); // hadir | setengah_hari | absen
            // Cross-module: indexed, no FK (CONVENTIONS §3). Nullable — office
            // staff are nobody's project cost.
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('note', 200)->nullable();
            // users.id of the clerk who filed the row — bulk entry writes many
            // rows in one request, and "who said Joko was absent on the 12th"
            // must have an answer per row, not per screen session.
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            // One row per person per day. The bulk endpoint upserts against
            // this key, so re-submitting the same site sheet corrects the day
            // instead of doubling it.
            $table->unique(['employee_id', 'date'], 'hr_attendances_employee_date_unique');
            $table->index(['project_id', 'date'], 'hr_attendances_project_date_index');
            $table->index('date', 'hr_attendances_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendances');
    }
};
