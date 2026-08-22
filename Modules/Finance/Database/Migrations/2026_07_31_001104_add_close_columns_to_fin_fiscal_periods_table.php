<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Who closed a period and when.
 *
 * fin_fiscal_periods.status has carried the whole answer since day one, which
 * means a closed January 2026 in the live dataset says nothing about who shut
 * it or on what evidence — and a period close is the moment a company stops
 * being able to change a month it has already reported. The two columns are
 * always written and always cleared in the SAME statement as `status`, so the
 * three can never disagree; isClosed() still derives from `status` alone, so a
 * stale closed_at (a re-seed re-asserting `open`) can mislead a display line
 * but never a gate.
 *
 * closed_by is a bare unsignedBigInteger with an index and no foreign key,
 * matching fin_journals.posted_by: users live in another module's table and the
 * Finance schema does not reach across that boundary.
 *
 * The Finance migration block (001100-001199) is exhausted; this continues the
 * date-forward pattern established by 2026_07_28_001101..001103.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_fiscal_periods', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('status');
            $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');

            $table->index('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('fin_fiscal_periods', function (Blueprint $table): void {
            $table->dropIndex(['closed_by']);
            $table->dropColumn(['closed_at', 'closed_by']);
        });
    }
};
