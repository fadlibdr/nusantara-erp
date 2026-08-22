<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanggal rencana tagih — when a termin FALLS DUE, as opposed to when it was
 * actually invoiced.
 *
 * billed_at is the only date the schedule had, and it only ever records the
 * past: an invoice was raised, here is when. It cannot answer the question that
 * matters — is anything owed to us today that nobody has billed? A NULL
 * billed_at on a termin nobody owes yet and a NULL billed_at on a termin three
 * months overdue are the same row.
 *
 * For a progress termin the milestone covers the gap: achieving "progres fisik
 * 50%" is the trigger, and prj_milestones carries its date. A CALENDAR termin
 * has no milestone and never will — it comes due because a quarter ended. The
 * live data shows the cost of that blind spot: CTR/2026/III/0003 (pemeliharaan
 * CCTV & akses kontrol) bills a flat Rp 120 juta per triwulan, Triwulan I went
 * out on 06-04-2026, and Triwulan II simply never did. Nothing in the schema
 * knew a quarter had come due, so no screen, report or alert could say so.
 *
 * Nullable on purpose: a progress termin is released by its milestone, not by
 * the calendar, and forcing a made-up date onto it would put invented rows into
 * the billing queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contract_termins', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('billing_condition');

            // The billing queue reads exactly this pair: due and not yet billed.
            $table->index(['due_date', 'billed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('crm_contract_termins', function (Blueprint $table): void {
            $table->dropIndex(['due_date', 'billed_at']);
            $table->dropColumn('due_date');
        });
    }
};
