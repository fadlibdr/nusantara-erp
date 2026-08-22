<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vendor bill a retention release raises.
 *
 * Releasing retention used to write one row in scm_retention_releases and
 * nothing else. Two things were missing and both were serious: no journal, so
 * `2-1500 Hutang Retensi Subkon` — credited by every approved opname bill —
 * was debited by NOTHING and grew for the life of the installation; and no
 * payable, so the money owed back to the subcontractor could not be disbursed
 * through the payment module at all (PaymentService allocates to ar_invoice /
 * ap_bill and to nothing else). The SPK screen said "released", the ledger said
 * "still owed", and the bank said nothing had moved.
 *
 * A release now issues an APPROVED AP bill for the amount, journalled
 * Dr 2-1500 / Cr 2-1100, and this column is the link between the two
 * subledgers.
 *
 * Why the link is STORED rather than derived: a release whose bill is later
 * cancelled is not a release any more — ApBillService::cancel reverses the
 * journal and the balance goes straight back into 2-1500 — and
 * RetentionService::balance() can only agree with the ledger about that if it
 * knows which bill belongs to which release.
 *
 * Nullable because a release recorded before this migration has no bill (and no
 * journal behind it either); such a row keeps counting as released, exactly as
 * it did before, so no reported balance shifts under an existing installation.
 * Cross-module reference, so indexed without a DB constraint — the mirror of
 * fin_ap_bills.subcontract_claim_id pointing the other way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scm_retention_releases')
            || Schema::hasColumn('scm_retention_releases', 'ap_bill_id')) {
            return;
        }

        Schema::table('scm_retention_releases', function (Blueprint $table): void {
            $table->unsignedBigInteger('ap_bill_id')->nullable()->after('subcontract_id'); // fin_ap_bills
            $table->index('ap_bill_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scm_retention_releases')
            || ! Schema::hasColumn('scm_retention_releases', 'ap_bill_id')) {
            return;
        }

        Schema::table('scm_retention_releases', function (Blueprint $table): void {
            $table->dropIndex(['ap_bill_id']);
            $table->dropColumn('ap_bill_id');
        });
    }
};
