<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode objek pajak — the DJP code that identifies WHAT was withheld against.
 *
 * e-Bupot Unifikasi keys every bukti potong on this code (24-xxx-xx for PPh 23,
 * 28-xxx-xx for PPh final Pasal 4(2), and so on). It is a statutory identifier
 * published by DJP and revised from time to time, so it belongs beside the rate
 * as editable master data rather than baked into the exporter — hard-coding a
 * guess would produce an export that imports cleanly and reports the wrong
 * object.
 *
 * Nullable on purpose: an installation that never files e-Bupot should not be
 * forced to invent one, and the exporter names the taxes that still need a code
 * rather than silently emitting blanks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_taxes', function (Blueprint $table): void {
            $table->string('object_code', 20)->nullable()->after('tax_type');
        });
    }

    public function down(): void
    {
        Schema::table('fin_taxes', function (Blueprint $table): void {
            $table->dropColumn('object_code');
        });
    }
};
