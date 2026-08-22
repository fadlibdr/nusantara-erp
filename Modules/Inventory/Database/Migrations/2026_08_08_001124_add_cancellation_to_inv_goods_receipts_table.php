<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A posted goods receipt can now be cancelled (StockService::cancelReceipt) —
 * the expensive third of audit T37 — so it needs the same trail every
 * cancellable document carries, mirroring inv_issues (migration 000491).
 *
 * Nothing is back-posted here: the columns are nullable and every existing row
 * keeps status 'posted' with a null cancelled_at. If a live GRN ever turns out
 * to be bogus, cancelling it is now a document the operator raises — which is
 * the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_goods_receipts', function (Blueprint $table): void {
            $table->dateTime('cancelled_at')->nullable()->after('status');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at'); // users.id
            // Long enough for a sentence an auditor can read a year later; the
            // request enforces a minimum too, so "salah" never reaches the trail.
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('inv_goods_receipts', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
    }
};
