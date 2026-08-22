<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Services\JournalService;
use Modules\Finance\Services\ProjectCostService;

/**
 * Data migration: give a site material issue back the project it was always for,
 * and move its cost out of office overhead into the project's HPP.
 *
 * THE PROBLEM ON A SHIPPED INSTALLATION. DatabaseSeeder runs Inventory fifth and
 * Projects seventh, so `prj_projects` was still empty when the demo's material
 * issue posted. seedIssue() looks the project up by canon code, stored null, and
 * StockService then did exactly the right thing for an issue that names no
 * project: it debited 6-4100 Beban Umum & Administrasi rather than a 5-xxxx HPP
 * account, and wrote no realisasi row. database/database.sqlite therefore
 * carries ISS/2026/VII/0001 — "Pengecoran kolom lantai 1 zona A, Gedung Graha
 * Sentosa", issued from that project's own site warehouse — as Rp 18.740.000 of
 * general administration, with project profitability for PRJ-2026-001
 * understating material cost by the same amount. The trial balance balances and
 * 1-1400 still agrees with the sub-ledger, which is what makes it easy to miss:
 * only the cost classification is wrong. InventoryDatabaseSeeder now seeds the
 * projects before the stock documents, so a fresh install never gets here; this
 * repairs the installations that already exist.
 *
 * WHAT QUALIFIES, read from data rather than guessed. A posted issue with no
 * project, drawn from a warehouse that belongs to one. A site warehouse exists
 * to serve its project, so material leaving it is that project's cost — the
 * inference the seeder could not make at the time only because the project row
 * did not exist yet. An issue from a warehouse with no project (a central store)
 * is genuinely unattributed overhead and is left alone.
 *
 * HOW THE CORRECTION IS BOOKED. Not by rewriting the original journal: a posted
 * journal is a record of what happened and is corrected by another journal. Only
 * the amount actually sitting in the generic expense account on that journal is
 * moved, read from the journal rather than re-derived, and split across the
 * 5-xxxx accounts by the same item_type rule StockService applies:
 *
 *   Dr 5-1100 Beban Material / 5-1400 Beban Alat   nilai pemakaian per kategori
 *   Cr 6-4100 Beban Umum & Administrasi            total
 *
 * The reclassifying lines carry the project, so GL-by-project agrees with the
 * cost ledger. Idempotent on every leg: the link is only written while it is
 * null, the reclass is recognised by its own reference type, and
 * ProjectCostService::record() keys on (reference_type, reference_id, category).
 * Every step is skippable — a data repair must never fail a deployment.
 */
return new class extends Migration
{
    /**
     * The account StockService debits for an issue that names no project.
     * A literal for the same reason it is a literal there: it is not a settable
     * parameter, and this file must read the same way with Inventory absent.
     */
    private const DEFAULT_ISSUE_EXPENSE_ACCOUNT = '6-4100';

    /** Reference type of the reclassifying entry, and its idempotency marker. */
    private const RECLASS_REFERENCE_TYPE = 'inventory_issue_cost_reclass';

    public function up(): void
    {
        if (! Schema::hasTable('inv_issues')
            || ! Schema::hasTable('inv_issue_items')
            || ! Schema::hasTable('inv_warehouses')
            || ! Schema::hasTable('fin_accounts')
            || ! Schema::hasTable('fin_journals')
            || ! Schema::hasTable('fin_journal_lines')
            || ! class_exists(JournalService::class)) {
            return; // fresh-install ordering, or a module is absent
        }

        // An empty chart is a fresh install whose seeders have not run: there is
        // no history to repair, and the seeder posts this correctly itself.
        if (DB::table('fin_accounts')->doesntExist()) {
            return;
        }

        foreach ($this->unattributedProjectIssues() as $issue) {
            $this->repair($issue);
        }
    }

    /**
     * Intentionally a no-op.
     *
     * Rolling back would mean un-posting a journal the ledger now depends on and
     * pushing project cost back into overhead, which has no business meaning.
     * Reverse it with a journal voucher if you must.
     */
    public function down(): void
    {
        // no-op — see the docblock above.
    }

    /**
     * Posted issues with no project, drawn from a warehouse that has one.
     *
     * @return iterable<int, object>
     */
    private function unattributedProjectIssues(): iterable
    {
        return DB::table('inv_issues')
            ->join('inv_warehouses', 'inv_warehouses.id', '=', 'inv_issues.warehouse_id')
            ->whereNull('inv_issues.deleted_at')
            ->where('inv_issues.status', 'posted')
            ->whereNull('inv_issues.project_id')
            ->whereNotNull('inv_warehouses.project_id')
            ->orderBy('inv_issues.id')
            ->get([
                'inv_issues.id',
                'inv_issues.code',
                'inv_issues.issue_date',
                'inv_issues.purpose',
                'inv_issues.issued_by',
                'inv_warehouses.project_id as warehouse_project_id',
            ]);
    }

    private function repair(object $issue): void
    {
        $projectId = (int) $issue->warehouse_project_id;

        if ($projectId <= 0 || ! $this->projectExists($projectId)) {
            return;
        }

        DB::table('inv_issues')
            ->where('id', $issue->id)
            ->whereNull('project_id')
            ->update(['project_id' => $projectId]);

        $byCategory = $this->issueTotalsByCategory((int) $issue->id);

        if ($byCategory === []) {
            return; // nothing valued: no cost to classify
        }

        $this->postReclass($issue, $projectId, $byCategory);
        $this->recordProjectCost($issue, $projectId, $byCategory);
    }

    private function projectExists(int $projectId): bool
    {
        if (! Schema::hasTable('prj_projects')) {
            return false;
        }

        return DB::table('prj_projects')->where('id', $projectId)->exists();
    }

    /**
     * Value of a posted issue split by cost category, from the unit costs the
     * original posting already stamped on each line. Same item_type rule as
     * StockService::issueCostCategory(): alat bantu is equipment, the rest is
     * material.
     *
     * @return array<string, float>
     */
    private function issueTotalsByCategory(int $issueId): array
    {
        $lines = DB::table('inv_issue_items')
            ->leftJoin('inv_items', 'inv_items.id', '=', 'inv_issue_items.item_id')
            ->where('inv_issue_items.issue_id', $issueId)
            ->get(['inv_issue_items.amount', 'inv_items.item_type']);

        /** @var array<string, float> $byCategory */
        $byCategory = [];

        foreach ($lines as $line) {
            $amount = round((float) $line->amount, 2);

            if ($amount <= 0.0) {
                continue;
            }

            $category = $line->item_type === 'tool'
                ? CostCategory::Equipment
                : CostCategory::Material;

            $byCategory[$category->value] = round(($byCategory[$category->value] ?? 0.0) + $amount, 2);
        }

        return $byCategory;
    }

    /**
     * Move whatever this issue left in the generic expense account onto the
     * 5-xxxx HPP accounts, tagged with the project.
     *
     * @param  array<string, float>  $byCategory
     */
    private function postReclass(object $issue, int $projectId, array $byCategory): void
    {
        if ($this->hasReclassJournal((int) $issue->id)) {
            return;
        }

        $expenseAccountId = $this->postableAccountId(self::DEFAULT_ISSUE_EXPENSE_ACCOUNT);

        if ($expenseAccountId === null) {
            return;
        }

        $misposted = $this->mispostedAmount((int) $issue->id, $expenseAccountId);

        if ($misposted <= 0.0) {
            return; // never journaled, or already classified as project cost
        }

        // The split has to account for exactly what is sitting in the expense
        // account. If it does not, the journal was not this migration's to
        // interpret and a human should look at it.
        if (round(array_sum($byCategory), 2) !== $misposted) {
            return;
        }

        $lines = [];

        foreach ($byCategory as $category => $amount) {
            $accountCode = CostCategory::from($category)->cogsAccountCode();

            if ($this->postableAccountId($accountCode) === null) {
                return; // incomplete chart: leave the whole entry alone
            }

            $lines[] = [
                'account_code' => $accountCode,
                'debit' => $amount,
                'description' => "Pemakaian material {$issue->code}",
                'project_id' => $projectId,
            ];
        }

        // The credit REVERSES the original mis-posted 6-4100 debit, and that
        // debit carries no project. Tagging the reversal with the project would
        // net the project-tagged P&L movement to zero — the project would gain
        // nothing and 6-4100 would acquire a project-tagged CREDIT balance, i.e.
        // a negative operating expense reported as income against that project.
        // Mirror the entry being corrected: no project on this leg.
        $lines[] = [
            'account_code' => self::DEFAULT_ISSUE_EXPENSE_ACCOUNT,
            'credit' => $misposted,
            'description' => "Reklasifikasi beban umum ke HPP proyek {$issue->code}",
            'project_id' => null,
        ];

        try {
            app(JournalService::class)->autoPost(
                self::RECLASS_REFERENCE_TYPE,
                (int) $issue->id,
                $lines,
                $this->dateOf($issue->issue_date),
                "Issue {$issue->code} — reklasifikasi pemakaian material ke HPP proyek",
                $this->postingUserId($issue->issued_by),
            );
        } catch (Throwable) {
            // a closed period, or a chart this migration may not post into:
            // the link and the cost ledger still stand on their own
        }
    }

    /**
     * Debit balance this issue's own posted journal left in the generic expense
     * account. Read from the journal, never re-derived.
     */
    private function mispostedAmount(int $issueId, int $expenseAccountId): float
    {
        $journalIds = DB::table('fin_journals')
            ->whereNull('deleted_at')
            ->where('reference_type', 'inventory_issue')
            ->where('reference_id', $issueId)
            ->where('status', 'posted')
            ->pluck('id');

        if ($journalIds->isEmpty()) {
            return 0.0;
        }

        $rows = DB::table('fin_journal_lines')
            ->whereIn('journal_id', $journalIds)
            ->where('account_id', $expenseAccountId)
            ->get(['debit', 'credit']);

        $balance = 0.0;

        foreach ($rows as $row) {
            $balance = round($balance + round((float) $row->debit, 2) - round((float) $row->credit, 2), 2);
        }

        return max($balance, 0.0);
    }

    private function hasReclassJournal(int $issueId): bool
    {
        return DB::table('fin_journals')
            ->whereNull('deleted_at')
            ->where('reference_type', self::RECLASS_REFERENCE_TYPE)
            ->where('reference_id', $issueId)
            ->exists();
    }

    /**
     * @param  array<string, float>  $byCategory
     */
    private function recordProjectCost(object $issue, int $projectId, array $byCategory): void
    {
        if (! class_exists(ProjectCostService::class) || ! Schema::hasTable('fin_project_costs')) {
            return;
        }

        $description = mb_substr(
            "Pemakaian material {$issue->code} — ".(string) $issue->purpose,
            0,
            497,
        );

        foreach ($byCategory as $category => $amount) {
            try {
                app(ProjectCostService::class)->record(
                    $projectId,
                    $this->dateOf($issue->issue_date),
                    CostCategory::from($category),
                    'inventory_issue',
                    (int) $issue->id,
                    $description,
                    round($amount, 2),
                );
            } catch (Throwable) {
                // the GL correction is the load-bearing part; a cost-ledger
                // hiccup must not undo it
            }
        }
    }

    private function postableAccountId(string $code): ?int
    {
        $id = DB::table('fin_accounts')
            ->where('code', $code)
            ->where('is_postable', true)
            ->whereNull('deleted_at')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function postingUserId(mixed $issuedBy): ?int
    {
        if ($issuedBy !== null) {
            return (int) $issuedBy;
        }

        if (! Schema::hasTable('users')) {
            return null;
        }

        $id = DB::table('users')->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function dateOf(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }
};
