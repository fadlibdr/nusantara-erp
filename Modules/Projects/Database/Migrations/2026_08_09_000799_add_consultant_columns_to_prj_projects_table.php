<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konsultan MK / pengawas — the fourth party on every form this company prints.
 *
 * The owner's house forms carry a band of four boxes across the top: PEMILIK,
 * KONSULTAN MK, PROYEK, KONTRAKTOR. Three of them the ERP could already answer
 * (crm_customers, prj_projects, core_company). The second it could not: there
 * was no column anywhere in the system for the management-consultant firm that
 * supervises the job and signs "Menyetujui / menolak" on every laporan harian.
 * Without it the print path had a choice between an empty box on every form or
 * a name typed into a template, and a supervising firm's name is not something
 * a template may invent.
 *
 * Nullable on purpose, and no backfill: a project with no MK is ordinary
 * (direct-appointment jobs and the system-integrator work have none), and the
 * paper form's answer to "no MK" is an empty box. Guessing a consultant for the
 * seeded projects would put a real firm's name on a document it never saw.
 *
 * consultant_role is the box's CAPTION, not decoration — the same party is
 * called "Konsultan MK" on a building job, "Konsultan Pengawas" on a government
 * one and "Konsultan Perencana" where the designer supervises. Default is the
 * commonest, so a user who fills in only the name gets the right heading.
 *
 * Numbering: block 000700-000799. Every by-10 slot is taken and 000798 is the
 * highest in use, so this continues by +1 inside the block — the escape hatch
 * documented at 2026_08_01_000796_create_prj_defects_table.php:22-25.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prj_projects', function (Blueprint $table): void {
            $table->string('consultant_name')->nullable()->after('customer_id');
            $table->string('consultant_role', 60)->nullable()->default('Konsultan MK')->after('consultant_name');
        });
    }

    public function down(): void
    {
        Schema::table('prj_projects', function (Blueprint $table): void {
            $table->dropColumn(['consultant_name', 'consultant_role']);
        });
    }
};
