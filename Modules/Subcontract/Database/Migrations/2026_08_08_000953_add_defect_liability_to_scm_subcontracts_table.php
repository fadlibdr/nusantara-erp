<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gate retensi subkon — temuan #75.
 *
 * Retention exists as a jaminan cacat mutu for the masa pemeliharaan, and
 * nothing in scm_subcontracts recorded when that period ends: the table only
 * carried start/end dates of the WORK. RetentionService::release checked the
 * ledger balance and nothing else, so 5 % retention could be released the day
 * after the first opname — the customer side has prj_bast.retention_release_due
 * for exactly this, the subcon side had nothing.
 *
 * defect_liability_until is the date the masa pemeliharaan ends (in practice
 * the BAST II date agreed in the SPK). release() now refuses before that date
 * — and on an SPK that never recorded one — unless the releaser states an
 * override reason, which is kept on the release row so the audit trail shows
 * WHO decided the guarantee could be let go early and WHY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scm_subcontracts', function (Blueprint $table): void {
            $table->date('defect_liability_until')->nullable()->after('end_date');
        });

        Schema::table('scm_retention_releases', function (Blueprint $table): void {
            $table->string('override_reason', 500)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('scm_retention_releases', function (Blueprint $table): void {
            $table->dropColumn('override_reason');
        });

        Schema::table('scm_subcontracts', function (Blueprint $table): void {
            $table->dropColumn('defect_liability_until');
        });
    }
};
