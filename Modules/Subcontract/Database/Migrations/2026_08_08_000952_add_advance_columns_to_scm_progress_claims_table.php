<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uang muka subkon — temuan #49.
 *
 * DP mobilisasi 10–20 % is standard on a construction SPK, and the system had
 * no way to record it at all: ApBillService refuses advances for anything but
 * a PO ("Uang muka hanya dapat dibuat atas pesanan pembelian"), this table had
 * no advance columns, and the ClaimService formula knew no DP recovery. Forced
 * through a manual bill instead, the DPP is debited straight to project cost —
 * so the subcon cost is booked TWICE by the time the opname is billed.
 *
 * The shape mirrors the vendor-PO advance pattern in ApBillService:
 *
 *   is_advance                 the claim is a DP claim, not an opname: it has
 *                              no progress lines, withholds no retention and
 *                              no PPh (both are charged on WORK, and a DP buys
 *                              no work yet), and its payout debits the prepaid
 *                              asset 1-1500 — never project cost;
 *   advance_recovery_amount    on later ordinary opnames: the proportional
 *                              slice of the DP this opname pays back. It
 *                              reduces net_payable and, on the opname bill,
 *                              credits 1-1500 back out — the same netting a PO
 *                              final bill does to its uang muka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scm_progress_claims', function (Blueprint $table): void {
            $table->boolean('is_advance')->default(false)->after('claim_no');
            $table->decimal('advance_recovery_amount', 18, 2)->default(0)->after('pph_amount');
        });
    }

    public function down(): void
    {
        Schema::table('scm_progress_claims', function (Blueprint $table): void {
            $table->dropColumn(['is_advance', 'advance_recovery_amount']);
        });
    }
};
