<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which project a payslip's wages belong to, if any.
 *
 * Resolved when the run is CALCULATED, from the manpower assignments that
 * overlap the payroll period, and frozen on the payslip from that moment. It is
 * not resolved at posting time on purpose: assignments change, and a payslip
 * that silently re-allocates itself after the fact would make the same approved
 * run post to different projects on different days.
 *
 * Null means office overhead — the wage goes to 6-1100 rather than 5-1200 and
 * carries no project cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table): void {
            // No FK constraint: hr_ and prj_ are separate modules, and the
            // convention in this codebase is that cross-module references are
            // plain ids (see fin_project_costs.project_id).
            $table->unsignedBigInteger('project_id')->nullable()->after('employee_id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->dropIndex(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};
