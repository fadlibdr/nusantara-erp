<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-3b (R2-rekap-1): the PPh 21 recap must say how a slip was computed —
 * with or without the 20 % no-tax-id surcharge — and that fact lived only in
 * the employee row AT THE TIME of the run. Read from today's row it flips
 * whenever HR completes a NIK afterwards. So the flag payroll actually used
 * (Employee::hasTaxId() at calculate time) is frozen on the slip, like every
 * other snapshot column here. Nullable: slips computed before this column
 * have no record, and the recap says so instead of guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->boolean('has_tax_id')->nullable()->after('pph21_amount');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->dropColumn('has_tax_id');
        });
    }
};
