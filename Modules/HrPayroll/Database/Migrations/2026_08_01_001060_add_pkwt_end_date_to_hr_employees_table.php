<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PP 35/2021 (UU Cipta Kerja): a PKWT worked past its end date becomes PKWTT
 * demi hukum — the company acquires a permanent employee by forgetting a date.
 * hr_employees already distinguishes PKWT from PKWTT (employment_type kontrak
 * vs tetap); what it never recorded is WHEN each PKWT ends. Both kontrak
 * employees in the live data — EMP-0007 Joko Susilo and EMP-0008 Made Wirawan
 * — have no end date on file anywhere.
 *
 * Nullable on purpose: those two legacy rows must stay editable without HR
 * being forced to invent a date. The deadline watcher treats "active kontrak
 * employee with NULL pkwt_end_date" as an alarm in itself, so the gap nags
 * until it is filled instead of blocking the payroll master data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table): void {
            $table->date('pkwt_end_date')->nullable()->after('employment_type');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table): void {
            $table->dropColumn('pkwt_end_date');
        });
    }
};
