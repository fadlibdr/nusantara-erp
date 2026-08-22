<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis perubahan kontrak — temuan #61, eskalasi harga kontrak multi-tahun.
 *
 * A price adjustment under an escalation clause (standard on multi-year
 * government contracts, computed from BPS indices) had no path of its own:
 * the only way to move a contract's value is a CCO, whose whole vocabulary —
 * title, reason, scope — says "pekerjaan tambah-kurang". So escalations were
 * recorded as added work, a wrong meaning that misleads exactly the audit
 * the clause exists for.
 *
 * This is deliberately NOT an index formula engine: the escalation amount is
 * still computed outside and enters through value_change like any amendment.
 * The column only fixes the audit trail, so a CCO that is an escalation says
 * so. The default keeps every existing row meaning what it always meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contract_change_orders', function (Blueprint $table): void {
            // tambah_kurang | eskalasi_harga — cast to ChangeOrderType.
            $table->string('change_type', 30)->default('tambah_kurang')->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('crm_contract_change_orders', function (Blueprint $table): void {
            $table->dropColumn('change_type');
        });
    }
};
