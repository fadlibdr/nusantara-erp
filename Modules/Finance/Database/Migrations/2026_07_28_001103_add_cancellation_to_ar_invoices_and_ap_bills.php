<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who cancelled an approved invoice/bill, when, and why.
 *
 * DocumentStatus::Cancelled existed and half the module filtered it out
 * defensively, but NOTHING ever set it. An approved AR invoice — already in the
 * general ledger — could not be withdrawn: the fictitious receivable sat in the
 * aging report forever, the contract termin stayed stamped billed_at so the
 * replacement invoice was refused by ArInvoiceService's own guard, and the only
 * remaining move was a hand-written JV, which fixes the GL and leaves the
 * subledger disagreeing with it permanently.
 *
 * A cancellation is an accounting event, so it is recorded like one: the reason
 * is mandatory (an auditor asks "why" first), and the actor and timestamp are
 * stored on the document rather than only in core_approvals, because every
 * query that reports on cancelled documents already has the document row in
 * hand.
 *
 * WHICH PERIOD MUST BE OPEN — the document's own. The reversal is posted on the
 * ORIGINAL journal's date so the mistake and its undoing sit in the same month
 * and that month's trial balance comes out clean. It follows that a document
 * whose period is already closed cannot be cancelled at all: the statements for
 * that period have been issued, and the instrument for a mistake found after
 * closing is a nota kredit / JV in the open period, never a retroactive edit of
 * a closed one.
 *
 * Numbering: Finance's 001100–001199 block was exhausted on 2026_07_25 and
 * continued date-forward per the note in 2026_07_26_001100. Next free: 001104.
 */
return new class extends Migration
{
    private const TABLES = ['fin_ar_invoices', 'fin_ap_bills'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'cancelled_at')) {
                    $blueprint->timestamp('cancelled_at')->nullable();
                }

                // User semantics (users.id) — app-owned, no DB constraint,
                // matching how posted_by is carried on fin_journals.
                if (! Schema::hasColumn($table, 'cancelled_by')) {
                    $blueprint->unsignedBigInteger('cancelled_by')->nullable();
                }

                if (! Schema::hasColumn($table, 'cancellation_reason')) {
                    $blueprint->string('cancellation_reason', 500)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                foreach (['cancelled_at', 'cancelled_by', 'cancellation_reason'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }
};
