<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the BAST II prerequisite checklist said, and who chose to go past it.
 *
 * A year after the fact the question an auditor asks about the Rp 2.425.000.000
 * retensi on CTR/2026/I/0001 is not "was there an override" — it is "what was
 * true at the instant somebody released it". Only a snapshot answers that, so
 * prerequisite_snapshot is written on EVERY BAST II approval, clean or not.
 *
 * No backfill: `select count(*) from prj_bast` on the live demo file returns 0,
 * and no project has status='closed'. The gate switches on with zero legacy
 * exceptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prj_bast', function (Blueprint $table): void {
            // The checklist verbatim, as evaluated at approval time.
            $table->json('prerequisite_snapshot')->nullable()->after('retention_release_due');
            // Set only when a WARNING was actually lifted. Blocks can never be
            // talked past, so a reason recorded here always corresponds to a
            // check the approver saw fail and decided to accept.
            $table->text('prerequisite_override_reason')->nullable()->after('prerequisite_snapshot');
            // users.id — app-owned, no DB constraint, matching the rest of the
            // module's actor columns.
            $table->unsignedBigInteger('prerequisite_override_by')->nullable()->after('prerequisite_override_reason');
            $table->dateTime('prerequisite_override_at')->nullable()->after('prerequisite_override_by');
        });
    }

    public function down(): void
    {
        Schema::table('prj_bast', function (Blueprint $table): void {
            $table->dropColumn([
                'prerequisite_snapshot',
                'prerequisite_override_reason',
                'prerequisite_override_by',
                'prerequisite_override_at',
            ]);
        });
    }
};
