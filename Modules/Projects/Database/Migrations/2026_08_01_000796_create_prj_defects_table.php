<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Register defect (daftar temuan / punch list).
 *
 * There was nowhere in the system to write down a single finding. PRJ-2026-001
 * holds Rp 2.425.000.000 of retensi (5% of Rp 48.500.000.000, crm_contract_termins
 * id 5, still unbilled) precisely as security against defects, and the only place
 * a defect ever appeared was prose — prj_bast.notes, or the rejection note
 * "Masih ada defect list terbuka." — with no severity, no owner, no due date and
 * no way to ask "what is still open on this job".
 *
 * The consequence is the one this table exists to stop: BAST II could be approved
 * with the punch list untouched, and approving BAST II closes the project and
 * publishes the date on which Rp 2,4 miliar of the customer's security becomes
 * collectible. The gate in BastPrerequisiteService reads its hard block straight
 * off (project_id, status, severity), which is why that pair is the first index.
 *
 * Numbering: block 000700-000799. 000780 is the K3 register and 000790/000795 are
 * the EVM baseline tables, so the by-10 slots are gone; this continues by 1 inside
 * the block the way Finance did with 2026_07_28_001101/001102.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_defects', function (Blueprint $table): void {
            $table->id();
            // Numbered because a refusal has to name its blocker: "BAST II belum
            // dapat disetujui — 2 temuan berat masih terbuka (DEF/2026/VIII/0001,
            // DEF/2026/VIII/0004)" is actionable, "2 temuan berat" is not.
            $table->string('code', 40)->unique();
            $table->foreignId('project_id')->constrained('prj_projects')->cascadeOnDelete();

            // Optional attachment to a work package ("B.3 Pembesian besi beton
            // ulir"). Nullable because half a punch list is found by walking a
            // floor, not by reading a WBS.
            $table->foreignId('wbs_task_id')->nullable()->constrained('prj_wbs_tasks')->nullOnDelete();
            // Whose warranty this is. The M&E subcontractor's retensi on
            // SPK/2026/III/0002 is held for exactly this kind of item.
            // scm_subcontracts is migrated at 2026_07_25_000900, which sorts
            // before this file, so the constraint is safe to declare here.
            $table->foreignId('subcontract_id')->nullable()->constrained('scm_subcontracts')->nullOnDelete();

            // Same vocabulary as prj_safety_incidents.location — "Lantai 5, zona B".
            // A defect with no WBS row still happened somewhere.
            $table->string('location', 150)->nullable();
            $table->string('title', 200);          // the punch-list line itself
            $table->text('description')->nullable();
            $table->string('severity', 20);        // critical | major | minor
            $table->string('source', 20);          // handover | warranty | internal
            $table->string('status', 20)->default('open'); // open … closed | waived

            $table->date('reported_on');
            // User semantics (users.id) — app-owned, no DB constraint, following
            // prj_safety_incidents.created_by.
            $table->unsignedBigInteger('reported_by')->nullable();

            $table->foreignId('responsible_employee_id')->nullable()
                ->constrained('hr_employees')->nullOnDelete();
            $table->date('due_date')->nullable();  // target perbaikan; drives is_overdue

            $table->date('fixed_at')->nullable();  // the contractor says it is done
            // …and the customer/MK says they accept it. This pair is the whole
            // evidence BAST II rests on, which is why they are two columns and
            // not one boolean.
            $table->date('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();

            $table->text('resolution_note')->nullable(); // how, or why it was waived

            $table->timestamps();
            $table->softDeletes();

            // "Apa yang masih terbuka di proyek ini" — the question the BAST II
            // gate asks on every approval and the screen asks on every load.
            $table->index(['project_id', 'status']);
            $table->index(['status', 'due_date']);
            $table->index('severity');
            $table->index('wbs_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_defects');
    }
};
