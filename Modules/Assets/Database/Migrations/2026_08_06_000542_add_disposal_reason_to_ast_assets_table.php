<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alasan pelepasan aset — dijual, hilang, atau di-scrap.
 *
 * disposal_date/disposal_value existed from day one; the reason did not, and
 * "notes" already carries operational remarks that must survive a disposal.
 * The reason is what an auditor asks first when 1-2300 drops by
 * Rp 420.000.000: without its own column the answer lives in somebody's
 * memory, and the disposal action could not require it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ast_assets', function (Blueprint $table): void {
            $table->string('disposal_reason', 200)->nullable()->after('disposal_value');
        });
    }

    public function down(): void
    {
        Schema::table('ast_assets', function (Blueprint $table): void {
            $table->dropColumn('disposal_reason');
        });
    }
};
