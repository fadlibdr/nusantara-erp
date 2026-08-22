<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrects the cost-reclassification journals written by
 * 2026_07_25_000496_link_project_issues_and_reclass_cost on installations that
 * ran it before the tagging was fixed.
 *
 * That migration reclassifies a material issue that was posted to 6-4100 Beban
 * Umum & Administrasi before the issue carried a project, moving it to the
 * project's HPP account. It tagged BOTH legs with the project — but the entry
 * being corrected, the original 6-4100 debit, carries no project at all.
 *
 * The result was a project-tagged credit sitting in an operating expense
 * account: the project-tagged P&L movement of the whole reclass netted to zero,
 * so the project gained nothing, and a negative expense read as income against
 * that project. On the shipped demo that showed up as
 * ReportService::profitLoss(..., projectId: 1) reporting operating_expenses of
 * -18.740.000 while projectProfitability(1) reported the cost correctly.
 *
 * The fix is to strip the project from the credit leg only, so it mirrors the
 * debit it reverses. The journal stays balanced — only the project tag moves —
 * so no re-posting and no period reopening is involved.
 */
return new class extends Migration
{
    private const RECLASS_REFERENCE_TYPE = 'inventory_issue_cost_reclass';

    private const EXPENSE_ACCOUNT = '6-4100';

    public function up(): void
    {
        foreach (['fin_journals', 'fin_journal_lines', 'fin_accounts'] as $table) {
            if (! Schema::hasTable($table)) {
                return; // Finance absent: nothing was ever posted to correct.
            }
        }

        $accountId = DB::table('fin_accounts')
            ->whereNull('deleted_at')
            ->where('code', self::EXPENSE_ACCOUNT)
            ->value('id');

        if ($accountId === null) {
            return;
        }

        $journalIds = DB::table('fin_journals')
            ->whereNull('deleted_at')
            ->where('reference_type', self::RECLASS_REFERENCE_TYPE)
            ->pluck('id');

        if ($journalIds->isEmpty()) {
            return; // fresh install, or 000496 never had anything to reclassify
        }

        // Only the credit leg on the expense account, and only where a project
        // is still attached — so re-running this changes nothing.
        DB::table('fin_journal_lines')
            ->whereIn('journal_id', $journalIds)
            ->where('account_id', $accountId)
            ->where('credit', '>', 0)
            ->whereNotNull('project_id')
            ->update(['project_id' => null]);
    }

    /**
     * Deliberately a no-op: restoring the project tag would restore the defect,
     * and the journals themselves are untouched either way.
     */
    public function down(): void {}
};
