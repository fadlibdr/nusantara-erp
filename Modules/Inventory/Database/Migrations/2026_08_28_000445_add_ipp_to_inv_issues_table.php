<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-ENG: link a material issue to the Ijin Pelaksanaan Pekerjaan it draws
 * material for.
 *
 * The permit is where the work-package attribution already lives — an IPP
 * names its wbs_task_id once, when the PM authorises the work — so a bon that
 * points at the IPP inherits that attribution as its header default
 * (IssueService) instead of asking the storeman to re-type a WBS id the
 * variance report will then have to trust. A bon WITHOUT an IPP on a project
 * that HAS active IPPs is not blocked; it is asked to confirm
 * (confirm_without_ipp, the PriceDeviationService pattern), because site
 * consumables outside any permit are real.
 *
 * Nullable and INDEX, not unique: many bons serve one permit over its
 * duration, and null stays the truth for office bons, field-report bons and
 * every row written before Engineering existed. Cross-module reference
 * (eng_work_permits_ipp) — indexed, no DB constraint, same shape as
 * inv_issues.project_id (CONVENTIONS §3). Forward-only: no backfill is
 * possible or honest, since no existing bon ever named a permit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inv_issues') || Schema::hasColumn('inv_issues', 'ipp_id')) {
            return;
        }

        Schema::table('inv_issues', function (Blueprint $table): void {
            $table->unsignedBigInteger('ipp_id')->nullable()->after('field_report_id');

            $table->index('ipp_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inv_issues') || ! Schema::hasColumn('inv_issues', 'ipp_id')) {
            return;
        }

        Schema::table('inv_issues', function (Blueprint $table): void {
            $table->dropIndex(['ipp_id']);
            $table->dropColumn('ipp_id');
        });
    }
};
