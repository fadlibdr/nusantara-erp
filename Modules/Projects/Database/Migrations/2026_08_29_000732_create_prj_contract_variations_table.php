<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 — the VOLUME face of an approved CCO, per BOQ item.
 *
 * The opname ceiling is "qty kontrak + CCO", and half of that number did not
 * exist anywhere in this database. `crm_contract_change_orders` is a VALUE
 * document by deliberate design — signed value_change, ppn_change, no lines at
 * all, and its own migration explains that it never touches existing termins —
 * so it can say "+ Rp 1.250.000.000 pekerjaan tambah" and cannot say "+ 500 m3
 * beton K-350 on item B.2". Without the second sentence the ceiling silently
 * degrades to the original BOQ, and every legitimate addendum volume gets
 * refused at 422 with no way out but disabling the gate.
 *
 * WHY IT LIVES IN PROJECTS AND NOT IN CRM. Delivery owns measured volume:
 * prj_wbs_tasks already carries boq_item_id and the value weights, the opname
 * measures against those items, and Projects → Crm / Estimation are both
 * allowed arrows (ARCHITECTURE.md) while the reverse is not. Putting lines on
 * the CCO itself would also change what a signed, approved change order means
 * after the fact; this table instead records what the QS read off the addendum
 * BOQ, keyed to the change order that authorises it.
 *
 * ONLY AN APPROVED CCO RAISES THE CEILING. The rows may be entered while the
 * change order is still a draft — that is how a QS works — but
 * MeasurementService reads crm_contract_change_orders.status by value and
 * counts nothing else. Signed is signed.
 *
 * qty_change is SIGNED for the same reason value_change is: pekerjaan kurang
 * is as ordinary as pekerjaan tambah, and it LOWERS the ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_contract_variations', function (Blueprint $table): void {
            $table->id();
            // All three are Crm/Estimation ids: indexed, no constraint.
            $table->unsignedBigInteger('contract_id');      // crm_contracts
            $table->unsignedBigInteger('change_order_id');  // crm_contract_change_orders
            $table->unsignedBigInteger('boq_item_id');      // est_boq_items
            $table->decimal('qty_change', 15, 3);           // SIGNED
            $table->string('unit', 20)->nullable();
            $table->string('notes', 300)->nullable();
            $table->timestamps();

            // One line per item per change order — a second row for the same
            // pair would double the ceiling with nothing on paper to match.
            $table->unique(['change_order_id', 'boq_item_id']);
            $table->index('contract_id');
            $table->index('boq_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_contract_variations');
    }
};
