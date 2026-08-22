<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a stock issue to the service field report that consumed the parts.
 *
 * This is the Inventory half of the bridge the parts migration (ServiceDesk
 * 001260) promised: when a customer signs a field report, its parts become a
 * real issue — stock out at moving average, Dr beban / Cr 1-1400 Persediaan —
 * created and posted inside the acknowledgement's own transaction
 * (FieldReportService::acknowledge()).
 *
 * UNIQUE on purpose: one sign-off, one bon. The status guard (only a Submitted
 * report can be acknowledged) is the business rule; this index is the database
 * saying the same thing, so even two concurrent acknowledgements — which
 * SQLite's no-op lockForUpdate cannot serialise — cannot issue the same
 * report's parts twice. The second insert violates the index and the whole
 * second acknowledgement rolls back.
 *
 * FORWARD-ONLY, no backfill: PM/2026/VI/0001 was acknowledged 2026-06-10,
 * before this bridge existed, so its 1 x ITM-0004 CCTV Dome 4MP (Rp 1.850.000)
 * never left inv_stock_balances and never reached the GL. Creating that issue
 * here would fabricate a June accounting fact retroactively; the row stays
 * exactly as it is, and the physical 29-vs-system 30 difference belongs to the
 * next stock opname, the document that exists to record "counted differs from
 * system".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inv_issues') || Schema::hasColumn('inv_issues', 'field_report_id')) {
            return;
        }

        Schema::table('inv_issues', function (Blueprint $table): void {
            // svc_field_reports.id — cross-module reference (ServiceDesk),
            // no DB constraint, same shape as inv_issues.project_id.
            $table->unsignedBigInteger('field_report_id')->nullable()->after('wbs_task_id');

            $table->unique('field_report_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inv_issues') || ! Schema::hasColumn('inv_issues', 'field_report_id')) {
            return;
        }

        Schema::table('inv_issues', function (Blueprint $table): void {
            $table->dropUnique(['field_report_id']);
            $table->dropColumn('field_report_id');
        });
    }
};
