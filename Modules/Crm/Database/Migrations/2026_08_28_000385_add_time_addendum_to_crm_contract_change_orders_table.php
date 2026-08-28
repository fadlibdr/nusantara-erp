<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addendum waktu (P0-B) — a change order of type 'waktu' that extends the
 * contract in days instead of rupiah.
 *
 * days_change is SIGNED, the same argument value_change already makes one
 * column up: negative is pengurangan waktu (an accelerated deadline agreed
 * with the customer), which is as ordinary as an extension, and a separate
 * direction flag would be one more thing every reader forgets to apply.
 *
 * new_end_date stays NULL until the addendum is APPROVED, and is then computed
 * by the service from the contract's CURRENT end_date — never input. Storing a
 * projection at create time would repeat the mistake changeOrderValues()
 * refuses for money: with two addenda pending, each would promise a date that
 * ignores the other. Computing at approval also makes sequential addenda stack
 * for free — the second one builds on the end_date the first already shifted.
 *
 * crm_contracts.original_end_date mirrors original_value exactly: NULL on
 * every contract that has never had an approved time addendum, written ONCE by
 * the first approval, so it means "when did we promise to finish" rather than
 * a copy every later addendum overwrites. FORWARD-ONLY — no backfill: existing
 * contracts keep their dates and a NULL here keeps meaning "never extended".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contract_change_orders', function (Blueprint $table): void {
            $table->smallInteger('days_change')->nullable()->after('ppn_change');
            $table->date('new_end_date')->nullable()->after('days_change');
        });

        Schema::table('crm_contracts', function (Blueprint $table): void {
            $table->date('original_end_date')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('crm_contracts', function (Blueprint $table): void {
            $table->dropColumn('original_end_date');
        });

        Schema::table('crm_contract_change_orders', function (Blueprint $table): void {
            $table->dropColumn(['days_change', 'new_end_date']);
        });
    }
};
