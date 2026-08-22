<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give a material issue LINE the work package that consumed it.
 *
 * WHY THE HEADER COLUMN CANNOT CARRY THIS. inv_issues.wbs_task_id has existed
 * since 000440 and is null on every row of database/database.sqlite, and even
 * filled in it could not tell the truth about the one posted document there:
 * ISS/2026/VII/0001 "Pengecoran kolom lantai 1 zona A" issues 150 zak ITM-0001
 * Semen Portland — whose analysis is AHSP A.4.1.1.7 pasangan bata, WBS C.1 —
 * together with 80 btg ITM-0002 Besi Beton D16, whose analysis is A.4.3.1.10
 * pembesian, WBS B.3. Two work packages, one bon. One header value has to lie
 * about one of the two lines, and a posted issue can no longer be split. The
 * material variance report compares theory (AHSP coefficient x BOQ qty x WBS
 * progress) against actual per work package, so a lie here becomes a variance
 * on two packages at once: B.3 credited with 150 zak of cement it never used,
 * C.1 charged with none of it.
 *
 * The header keeps its meaning as the DEFAULT (IssueService::syncItems copies
 * it down), so the common single-package bon still costs the storeman one
 * field; the report reads the line.
 *
 * THE BACKFILL touches 0 rows on the demo database, because all 3 existing
 * lines belong to headers with a null wbs_task_id. It is here for the
 * installations where somebody did type an id into the old "ID tugas WBS"
 * number field: their header attribution is the best answer available for
 * lines written before this column existed, and losing it silently would be
 * worse than inheriting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inv_issue_items') || Schema::hasColumn('inv_issue_items', 'wbs_task_id')) {
            return;
        }

        Schema::table('inv_issue_items', function (Blueprint $table): void {
            // Cross-module reference (Projects) — indexed, no DB constraint, the
            // same shape inv_issues.wbs_task_id already uses. Nullable because a
            // bon for the site office genuinely belongs to no work package.
            $table->unsignedBigInteger('wbs_task_id')->nullable()->after('item_id');

            $table->index('wbs_task_id');
        });

        if (! Schema::hasColumn('inv_issues', 'wbs_task_id')) {
            return;
        }

        DB::table('inv_issue_items')
            ->whereNull('wbs_task_id')
            ->update([
                'wbs_task_id' => DB::raw(
                    '(select wbs_task_id from inv_issues where inv_issues.id = inv_issue_items.issue_id)'
                ),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('inv_issue_items') || ! Schema::hasColumn('inv_issue_items', 'wbs_task_id')) {
            return;
        }

        Schema::table('inv_issue_items', function (Blueprint $table): void {
            $table->dropIndex(['wbs_task_id']);
            $table->dropColumn('wbs_task_id');
        });
    }
};
