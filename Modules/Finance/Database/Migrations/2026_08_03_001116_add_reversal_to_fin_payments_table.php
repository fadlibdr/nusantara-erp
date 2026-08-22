<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who reversed a posted payment, when, and why.
 *
 * Until now a posted payment was the one value-bearing document in the system
 * with no way back: PaymentService::delete() demands draft and there was no
 * reversal at all. One receipt allocated to the wrong faktur therefore locked
 * that invoice out of cancellation FOR EVER — ArInvoiceService::cancel()
 * refuses any invoice with amount_paid > 0, and nothing could ever bring
 * amount_paid back to zero. The same trap closed on an AP bill paid against
 * the wrong vendor invoice.
 *
 * A reversal is NOT an edit. The posted journal stays exactly as it is and
 * JournalService::reverseFor() posts its mirror, so both the mistake and its
 * correction remain visible; PaymentStatus::Reversed is a NEW terminal state
 * beside Posted rather than a return to draft, because the money did move and
 * the bank statement will always say so.
 *
 * WHICH DATE THE MIRROR CARRIES is JournalService::reversalDate()'s decision,
 * not this table's: the payment's own date while that period is open and no
 * posted PSAK 115 run has measured it, otherwise today. See the note on
 * 2026_07_28_001103 for why a measured month may never be reopened.
 *
 * The actor/timestamp/reason live on the row as well as in core_approvals, for
 * the same reason cancelled_by/cancelled_at do on fin_ar_invoices: every query
 * that reports on reversed payments already has the payment row in hand.
 *
 * Numbering: Finance's 001100-001199 block was exhausted on 2026_07_25 and
 * continues date-forward; 2026_08_03_001115 is this wave's first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_payments')) {
            return;
        }

        Schema::table('fin_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('fin_payments', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable();
            }

            // User semantics (users.id) — app-owned, no DB constraint,
            // matching cancelled_by on fin_ar_invoices and posted_by on
            // fin_journals.
            if (! Schema::hasColumn('fin_payments', 'reversed_by')) {
                $table->unsignedBigInteger('reversed_by')->nullable();
            }

            if (! Schema::hasColumn('fin_payments', 'reversal_reason')) {
                $table->string('reversal_reason', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fin_payments')) {
            return;
        }

        Schema::table('fin_payments', function (Blueprint $table): void {
            foreach (['reversed_at', 'reversed_by', 'reversal_reason'] as $column) {
                if (Schema::hasColumn('fin_payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
