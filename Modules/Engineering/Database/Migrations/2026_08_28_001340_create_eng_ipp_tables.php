<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-ENG: Ijin Pelaksanaan Pekerjaan (IPP, FM-10-11 & Master IPP) — the
 * backbone of the drawing → material → IPP chain, header + four line tables.
 *
 * Four TABLES, not one table with a kind column, because the four line types
 * share almost nothing: a bahan line carries qty/unit against an optional
 * inventory item, an alat line a count, and the two reference line types are
 * single FKs into this module's own submittal tables (constrained — same
 * module). One nullable-everything table would let a drawing line carry a qty
 * and a bahan line a submittal, and the FormRequest would be the only thing
 * standing between that and the register.
 *
 * The header is Approvable (the ONE Engineering document that is — the spec
 * says so, and the internal approver is real: the site's own PM authorises the
 * work before it starts). The submit gate — every drawing line stamped
 * approved / approved-as-noted, every material line approved — lives in
 * IppService::submit, never in the trait or a controller.
 *
 * Cross-module: project_id → prj_projects, location_id → core_locations,
 * item_id → inv_items; indexed, no constraints (§3 — Core included: no module
 * table hard-references another module's schema, and Core's own rule is the
 * reverse arrow).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eng_work_permits_ipp', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // IPP/{Y}/{RM}/{N4}
            $table->unsignedBigInteger('project_id'); // prj_projects — indexed, no FK
            $table->string('scope', 20); // IppScope: struktur/arsitek/mep
            $table->unsignedBigInteger('location_id')->nullable(); // core_locations — indexed, no FK
            $table->text('description');
            $table->date('planned_start');
            $table->unsignedSmallInteger('duration_days');
            $table->string('status', 30)->default('draft'); // DocumentStatus, Approvable
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('location_id');
        });

        Schema::create('eng_ipp_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ipp_id')->constrained('eng_work_permits_ipp')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id')->nullable(); // inv_items — indexed, no FK
            $table->string('description', 200);
            $table->decimal('qty', 15, 3);
            $table->string('unit', 20);
            $table->timestamps();

            $table->index('item_id');
        });

        Schema::create('eng_ipp_equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ipp_id')->constrained('eng_work_permits_ipp')->cascadeOnDelete();
            $table->string('description', 150);
            $table->unsignedSmallInteger('qty');
            $table->string('notes', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('eng_ipp_drawings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ipp_id')->constrained('eng_work_permits_ipp')->cascadeOnDelete();
            $table->foreignId('drawing_submittal_id')->constrained('eng_drawing_submittals');
            $table->timestamps();

            $table->unique(['ipp_id', 'drawing_submittal_id']);
        });

        Schema::create('eng_ipp_material_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ipp_id')->constrained('eng_work_permits_ipp')->cascadeOnDelete();
            $table->foreignId('material_submittal_id')->constrained('eng_material_submittals');
            $table->timestamps();

            $table->unique(['ipp_id', 'material_submittal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eng_ipp_material_approvals');
        Schema::dropIfExists('eng_ipp_drawings');
        Schema::dropIfExists('eng_ipp_equipment');
        Schema::dropIfExists('eng_ipp_materials');
        Schema::dropIfExists('eng_work_permits_ipp');
    }
};
