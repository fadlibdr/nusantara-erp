<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A posted material issue can now be cancelled (StockService::cancelIssue), so
 * it needs the same trail every cancellable document in Finance carries.
 *
 * Nothing is back-posted here: the columns are nullable and every existing row
 * keeps status 'posted' with a null cancelled_at. The live ISS/2026/VII/0001
 * (Rp 18.740.000 of semen and besi against PRJ-2026-001) is untouched — if it
 * ever turns out to have been charged to the wrong project, cancelling it is now
 * a document the operator raises, which is the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_issues', function (Blueprint $table): void {
            $table->dateTime('cancelled_at')->nullable()->after('status');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at'); // users.id
            // Long enough for a sentence an auditor can read a year later; the
            // request enforces a minimum too, so "salah" never reaches the trail.
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('inv_issues', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
    }
};
