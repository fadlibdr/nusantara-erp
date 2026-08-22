<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payslips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees');
            $table->decimal('basic_salary', 18, 2)->default(0);
            $table->json('allowances')->nullable(); // snapshot of the employee's fixed allowances
            $table->decimal('allowances_total', 18, 2)->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0); // snapshot from the attendance recap
            $table->decimal('overtime_pay', 18, 2)->default(0);
            $table->decimal('thr_amount', 18, 2)->default(0);
            $table->decimal('gross_income', 18, 2)->default(0);
            // {kes_company, kes_employee, jht_company, jht_employee, jp_company, jp_employee, jkk_company, jkm_company}
            $table->json('bpjs')->nullable();
            $table->decimal('bpjs_employee_total', 18, 2)->default(0);
            $table->decimal('bpjs_company_total', 18, 2)->default(0);
            $table->char('ter_category', 1)->nullable(); // A | B | C (null for December Pasal 17 true-up)
            $table->decimal('ter_rate', 8, 4)->nullable();
            $table->decimal('pph21_amount', 18, 2)->default(0);
            $table->decimal('total_deductions', 18, 2)->default(0);
            $table->decimal('net_pay', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id'], 'hr_payslips_run_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payslips');
    }
};
