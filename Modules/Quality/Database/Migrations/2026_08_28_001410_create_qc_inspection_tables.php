<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-QC: an inspection (QCI) — a template filled in at a location on a day, and
 * its result rows.
 *
 * The header is Approvable (submit → approve, qc.approve, house maker-checker),
 * exactly as the IPP is: the contractor's QC records the sheet, a second holder
 * authorises it. THE SUBMIT GATE — an OPEN NCR at this location raised at an
 * earlier stage refuses the submit — lives in InspectionService::submit, never
 * in the trait or a controller. `witness_party` is a recorded fact (who
 * attended), not the approver.
 *
 * `passed` is the OVERALL verdict, DERIVED from the result rows (any `nok`
 * fails), written by InspectionService — never typed from a request, so the
 * sheet cannot claim a pass its own lines contradict.
 *
 * Cross-module: project_id → prj_projects, ipp_id → eng_work_permits_ipp,
 * location_id → core_locations, inspector_employee_id → hr_employees; all
 * unsignedBigInteger + index, NO constraint (CONVENTIONS §3). template_id and
 * the result's template_item_id are this module's OWN tables — constrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_inspections', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // QCI/{Y}/{RM}/{N4}
            $table->unsignedBigInteger('project_id'); // prj_projects — indexed, no FK
            $table->unsignedBigInteger('ipp_id')->nullable(); // eng_work_permits_ipp — indexed, no FK
            $table->unsignedBigInteger('location_id'); // core_locations — indexed, no FK
            $table->foreignId('template_id')->constrained('qc_inspection_templates');
            $table->date('inspected_at');
            $table->unsignedBigInteger('inspector_employee_id')->nullable(); // hr_employees — indexed, no FK
            $table->string('witness_party', 10)->nullable(); // WitnessParty: mk/owner
            // Overall verdict, DERIVED from the result rows (InspectionService).
            $table->boolean('passed')->default(true);
            $table->string('status', 30)->default('draft'); // DocumentStatus, Approvable
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('ipp_id');
            $table->index('location_id');
        });

        Schema::create('qc_inspection_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_id')->constrained('qc_inspections')->cascadeOnDelete();
            $table->foreignId('template_item_id')->constrained('qc_inspection_template_items');
            $table->string('result', 10); // ItemResult: ok/nok/na
            $table->string('remark', 300)->nullable();
            $table->timestamps();

            $table->unique(['inspection_id', 'template_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_inspection_results');
        Schema::dropIfExists('qc_inspections');
    }
};
