<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up berikutnya + tautan pelanggan pada lead — temuan #58.
 *
 * next_follow_up_at: crm_leads had no follow-up/next-action field of any
 * kind, so the early funnel — before a quotation exists to carry dates — had
 * nothing to remind anyone with. A date, not a datetime: "hubungi lagi
 * minggu depan" is a day, and a fake 00:00 time would only pretend precision.
 *
 * customer_id: the "Jadikan pelanggan" action must know which leads were
 * ALREADY converted — without this column the second click creates a
 * duplicate CUST- row that then has to be merged by hand. Nullable and not
 * backfilled: matching old leads to old customers by name similarity would
 * invent history. In-module reference, but unsignedBigInteger + index rather
 * than a constrained() FK — SQLite silently drops foreign keys added via
 * ALTER TABLE, and a constraint that exists only on fresh installs would be
 * a lie on the live database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->date('next_follow_up_at')->nullable()->after('notes');
            $table->unsignedBigInteger('customer_id')->nullable()->after('next_follow_up_at');

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropIndex(['customer_id']);
            $table->dropColumn(['next_follow_up_at', 'customer_id']);
        });
    }
};
