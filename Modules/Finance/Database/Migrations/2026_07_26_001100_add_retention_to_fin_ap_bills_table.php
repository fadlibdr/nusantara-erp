<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retention withheld from a subcontractor, on the bill that pays them.
 *
 * The claim already computed it — ClaimService writes `net_payable = gross −
 * retensi + PPN − PPh` — and RetentionService::balance() reports it as held.
 * The bill built from that claim ignored it and paid the gross, so the money
 * left while the system said it was retained. `2-1500 Hutang Retensi Subkon`
 * existed in the chart and was referenced nowhere.
 *
 * The DPP stays gross on purpose: PPN and PPh final are charged on the value of
 * work done, not on what is paid this month. Only the payable changes.
 *
 * NOTE ON THE NUMBER: Finance's block 001100–001199 (docs/CONVENTIONS.md) was
 * exhausted on 2026_07_25. This continues the same block on a later DATE, which
 * keeps the ordering and the ownership intact without taking ServiceDesk's
 * range. The next Finance migration should carry on from 2026_07_26_001101.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_ap_bills', function (Blueprint $table): void {
            $table->decimal('retention_amount', 18, 2)->default(0)->after('pph_amount');
        });
    }

    public function down(): void
    {
        Schema::table('fin_ap_bills', function (Blueprint $table): void {
            $table->dropColumn('retention_amount');
        });
    }
};
