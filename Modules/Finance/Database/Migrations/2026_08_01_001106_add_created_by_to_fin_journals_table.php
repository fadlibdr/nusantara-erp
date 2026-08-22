<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who KEYED the manual journal — the maker half of maker-checker for the JV.
 *
 * A hand-keyed JV (Dr 2-1100 Hutang Usaha / Cr 1-1210 Bank, Rp 111.000.000 in
 * the review probe) produces the identical ledger entry PaymentService::post()
 * writes, yet fin_journals carried only posted_by — so the forensic self-join
 * SegregationOfDuties relies on (who asserted it vs who authorised it) was
 * impossible for the one document type that can move bank money with no
 * approval trail at all.
 *
 * NULLABLE ON PURPOSE, twice over: pre-existing journals have no recorded maker
 * to backfill from, and journals minted by JournalService::autoPost() stamp
 * nobody deliberately — their gate is the approval of the document that called
 * them, and stamping that approver here would make the same person "the maker"
 * and wedge every AP bill approval on its own guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_journals', function (Blueprint $table): void {
            // Cross-module reference (users) — indexed, no DB constraint, the
            // same shape as posted_by in the create migration.
            $table->unsignedBigInteger('created_by')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('fin_journals', function (Blueprint $table): void {
            $table->dropIndex(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
