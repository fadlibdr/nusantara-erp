<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Name the warehouse the visit's spare parts leave from.
 *
 * The parts table (001260) promised "the actual stock issue is booked by the
 * Inventory module referencing the field report", but a stock issue cannot be
 * booked without knowing WHICH stock: inv_issues.warehouse_id is NOT NULL, and
 * the moving average that values the issue is a property of the (warehouse,
 * item) balance. Nothing in the ServiceDesk schema answered that question —
 * svc_contract_sites is a street address, not a stock location — so the report
 * header now carries it, one field for the whole visit, exactly like a bon.
 *
 * Nullable on purpose, twice over: a report with no parts never needs it, and
 * the acknowledged demo row PM/2026/VI/0001 predates this column and stays
 * null FOREVER — its 1 x ITM-0004 was consumed before the bridge existed and
 * is deliberately not re-issued (see FieldReportService::acknowledge()). A
 * report that DOES list parts is refused at acknowledgement until the field is
 * filled, because guessing a warehouse would misstate another warehouse's
 * moving average by exactly the guessed line's value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('svc_field_reports') || Schema::hasColumn('svc_field_reports', 'warehouse_id')) {
            return;
        }

        Schema::table('svc_field_reports', function (Blueprint $table): void {
            // inv_warehouses.id — cross-module reference (Inventory), indexed,
            // no DB constraint: the same shape svc_field_report_parts.item_id
            // and svc_tickets.customer_id already use.
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('technician_employee_id');

            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('svc_field_reports') || ! Schema::hasColumn('svc_field_reports', 'warehouse_id')) {
            return;
        }

        Schema::table('svc_field_reports', function (Blueprint $table): void {
            $table->dropIndex(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};
