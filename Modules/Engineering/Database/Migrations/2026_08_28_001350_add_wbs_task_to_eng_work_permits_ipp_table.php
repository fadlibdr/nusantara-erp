<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-ENG: the work package an IPP authorises — the column the spec's
 * inheritance rule quietly requires. "Bon yang menunjuk IPP mewarisi
 * wbs_task_id-nya" presumes the IPP HAS a wbs_task_id to inherit; the 001340
 * schema (built to the spec's explicit column list) does not carry one, so the
 * inheritance rule and the schema meet here, in the module's own block.
 *
 * Nullable on purpose: an IPP for preparatory work (pagar proyek, direksi
 * keet) may precede the WBS, and a null simply means a linked bon inherits
 * nothing and attributes its lines by hand as before. When filled, the value
 * is held to the same standard as the bon picker it feeds — a leaf of the same
 * project carrying a BOQ item (IppService::assertWbsTaskIsWorkPackage) — so
 * inheritance can never launder an id the bon's own request would refuse.
 *
 * Cross-module (prj_wbs_tasks): indexed, no DB constraint — CONVENTIONS §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eng_work_permits_ipp') || Schema::hasColumn('eng_work_permits_ipp', 'wbs_task_id')) {
            return;
        }

        Schema::table('eng_work_permits_ipp', function (Blueprint $table): void {
            $table->unsignedBigInteger('wbs_task_id')->nullable()->after('location_id');

            $table->index('wbs_task_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('eng_work_permits_ipp') || ! Schema::hasColumn('eng_work_permits_ipp', 'wbs_task_id')) {
            return;
        }

        Schema::table('eng_work_permits_ipp', function (Blueprint $table): void {
            $table->dropIndex(['wbs_task_id']);
            $table->dropColumn('wbs_task_id');
        });
    }
};
