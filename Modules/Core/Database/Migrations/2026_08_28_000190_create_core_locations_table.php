<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-ENG: core_locations — the hierarchical site breakdown (tower → lantai →
 * zona → as → ruang) of one project.
 *
 * It lives in CORE, not Engineering, because Engineering (IPP), Quality (P1-QC
 * inspections/NCR) and Projects (P3 zone certificates) will all point at the
 * same rows — and Core may depend on no module, so:
 *
 *  - project_id is a bare indexed unsignedBigInteger. NO constraint, NO
 *    Eloquent relation from Core to Projects (ARCHITECTURE.md: the dependency
 *    arrows point AT Core, never out of it).
 *  - parent_id IS constrained — it points at this very table.
 *
 * code is globally unique, the house convention for master data (CONVENTIONS
 * §4; WH-PRJ-2026-001 shows the idiom: the code carries the project identity).
 * That is also what keeps the flat master-data importer honest — it matches
 * rows on one business code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_locations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id'); // prj_projects — indexed, no FK (Core depends on no module)
            $table->foreignId('parent_id')->nullable()->constrained('core_locations');
            $table->string('kind', 20); // LocationKind: tower/floor/zone/axis/room
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index(['project_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_locations');
    }
};
