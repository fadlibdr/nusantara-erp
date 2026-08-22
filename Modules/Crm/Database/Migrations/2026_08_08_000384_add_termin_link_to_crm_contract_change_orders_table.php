<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan CCO → termin penagihannya (temuan #14).
 *
 * ContractChangeOrderService has promised since its first line that 'added
 * scope is billed through new termins' — and no path ever created one. An
 * approved contract's schedule is frozen (ContractService::update refuses the
 * whole document, and refuses a replacement schedule outright once anything is
 * billed), so the value an approved CCO added could only be billed through a
 * manual invoice: no due_date, no billed_at, invisible to the antrean siap
 * tagih — exactly the 'pendapatan tercecer' the termin schedule exists to
 * prevent.
 *
 * The column lives on the CHANGE ORDER, pointing at the termin it spawned. It
 * is the idempotency stamp ContractChangeOrderService::scheduleTermin()
 * re-reads inside its transaction, so a double-clicked wizard cannot schedule
 * the same added value twice. nullOnDelete: should the termin row ever
 * disappear, the CCO becomes schedulable again instead of pointing at nothing
 * while blocking its own billing forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contract_change_orders', function (Blueprint $table): void {
            $table->foreignId('termin_id')->nullable()->after('status')
                ->constrained('crm_contract_termins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_contract_change_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('termin_id');
        });
    }
};
