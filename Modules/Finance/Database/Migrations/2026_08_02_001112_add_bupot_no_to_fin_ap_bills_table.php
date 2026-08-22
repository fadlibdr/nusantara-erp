<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor bukti potong, stored on the bill that withheld the tax.
 *
 * It used to be derived from the row's POSITION in an export run
 * (TaxExportService: `sprintf('BP-%04d%02d-%04d', ..., ++$sequence)`), and
 * blocked rows consumed no number. So re-running a masa after doing what the
 * blockers card tells you to do — key in the missing NPWP — renumbered every
 * certificate already handed to a vendor: in the audit probe on masa 2026-06,
 * BP-202606-0002 named CV Karya Sipil Sejahtera for Rp 18.550.000 in run 1 and
 * PT Mekanika Prima for Rp 7.950.000 in run 2. A bukti potong number is the
 * legal reference a vendor cites when claiming its PPh credit; it is not a row
 * index, and two vendors must never hold the same one.
 *
 * Minted once, when the bill is APPROVED — that is when the withholding becomes
 * a fact, the bill becomes un-editable, and the certificate can be issued. The
 * unique index is the real protection, not the sequence lock: lockForUpdate()
 * is a no-op on SQLite, so a duplicate number has to be impossible to COMMIT.
 *
 * Numbering: Finance's 001100-001199 block was exhausted on 2026_07_25 and
 * continues date-forward per the note in 2026_07_26_001100. Next free: 001113.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_ap_bills') || Schema::hasColumn('fin_ap_bills', 'bupot_no')) {
            return;
        }

        Schema::table('fin_ap_bills', function (Blueprint $table): void {
            $table->string('bupot_no', 24)->nullable()->after('faktur_pajak_no');
            $table->unique('bupot_no');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fin_ap_bills') || ! Schema::hasColumn('fin_ap_bills', 'bupot_no')) {
            return;
        }

        Schema::table('fin_ap_bills', function (Blueprint $table): void {
            $table->dropUnique(['bupot_no']);
            $table->dropColumn('bupot_no');
        });
    }
};
