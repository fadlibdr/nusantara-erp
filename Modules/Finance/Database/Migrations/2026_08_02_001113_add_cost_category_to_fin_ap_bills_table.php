<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cost bucket a bill charges, on the bill itself.
 *
 * ApBillService::costCategory() derives it from the SOURCE DOCUMENT alone —
 * "names a PO" => material — so a crane hired for Rp 180.000.000 through a
 * services PO (no item_id on any line) debited 5-1100 Beban Material Proyek and
 * wrote a fin_project_costs row with cost_category 'material'. The RAP
 * comparison then reported material realisasi Rp 180 juta over budget and
 * equipment Rp 180 juta under, on a project that bought no extra material.
 * CostCategory's own docblock says the values are "value-compatible with
 * Estimation's RAP categories so realisasi lines up against budget", which is
 * exactly what stopped being true.
 *
 * Nullable, and the derivation stays as the default: an operator who says
 * nothing gets today's behaviour, and one who knows the crane is Alat can say
 * so. Deliberately NOT a heuristic on "the PO has no stock line" — consultancy,
 * mobilisasi and security are services too, and calling all of them Alat only
 * moves the misclassification somewhere else.
 *
 * Numbering: Finance's 001100-001199 block was exhausted on 2026_07_25 and
 * continues date-forward per the note in 2026_07_26_001100. Next free: 001114.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_ap_bills') || Schema::hasColumn('fin_ap_bills', 'cost_category')) {
            return;
        }

        Schema::table('fin_ap_bills', function (Blueprint $table): void {
            $table->string('cost_category', 20)->nullable()->after('project_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fin_ap_bills') || ! Schema::hasColumn('fin_ap_bills', 'cost_category')) {
            return;
        }

        Schema::table('fin_ap_bills', function (Blueprint $table): void {
            $table->dropColumn('cost_category');
        });
    }
};
