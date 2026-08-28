<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-QC: the Non-Conformance Report (NCR).
 *
 * NOT Approvable and NOT DocumentStatus — status is NcrStatus
 * (open/under_correction/verified/closed), its own lifecycle (see the enum). An
 * NCR is raised, corrected by the responsible party, and verified by QC; it is
 * not submitted and approved into being.
 *
 * `stage` is the hold-point the nonconformance was found at — inherited from the
 * originating inspection's template stage when inspection_id is set, supplied
 * directly for a standalone NCR (NcrService). THE BLOCK compares it: an OPEN NCR
 * at a location refuses the submit of a LATER-stage inspection at that same
 * location (InspectionService::submit), and blocks BAST I on the project
 * (BastPrerequisiteService).
 *
 * RESPONSIBLE PARTY IS EXACTLY ONE OF two — an own employee OR a subcontractor,
 * never both and never neither (the XOR is enforced in NcrService and the
 * FormRequest; the schema keeps both nullable because either may be the one
 * that is null).
 *
 * Cross-module: project_id → prj_projects, location_id → core_locations,
 * responsible_employee_id → hr_employees, subcontract_id → scm_subcontracts,
 * verified_by → users; all unsignedBigInteger + index, NO constraint
 * (CONVENTIONS §3). inspection_id is this module's own table — nullable,
 * constrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_ncr', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // NCR/{Y}/{RM}/{N4}
            $table->unsignedBigInteger('project_id'); // prj_projects — indexed, no FK
            $table->foreignId('inspection_id')->nullable()->constrained('qc_inspections');
            $table->unsignedBigInteger('location_id'); // core_locations — indexed, no FK
            $table->string('stage', 20); // InspectionStage — the hold-point, for the block
            $table->text('description');
            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();
            $table->text('preventive_action')->nullable();
            // Exactly one of these two is set (XOR — NcrService).
            $table->unsignedBigInteger('responsible_employee_id')->nullable(); // hr_employees
            $table->unsignedBigInteger('subcontract_id')->nullable(); // scm_subcontracts
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable(); // users
            $table->date('verified_at')->nullable();
            $table->string('status', 20)->default('open'); // NcrStatus
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('location_id');
            $table->index('responsible_employee_id');
            $table->index('subcontract_id');
            $table->index('verified_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_ncr');
    }
};
