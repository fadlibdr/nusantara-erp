<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-ENG: register shop drawing (FM-10-01/21). One row is one drawing on the
 * project's daftar rencana persetujuan; its approval history lives in
 * eng_drawing_submittals, and `status` mirrors the latest submittal's state
 * (moved only by DrawingSubmittalService).
 *
 * `number` is the DRAFTER'S own drawing number (GSP-ST-101), unique per
 * project, not a HasDocumentNumber code: the register records numbers the
 * engineering office already issued on the title block, and re-numbering
 * somebody's title block from a counter would make the register disagree with
 * every printed sheet on site.
 *
 * Cross-module: project_id → prj_projects, indexed, NO constraint
 * (CONVENTIONS §3 — Engineering → Projects is a one-way service/relation
 * dependency, never a schema one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eng_drawings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id'); // prj_projects — indexed, no FK
            $table->string('number', 60);
            $table->string('title', 200);
            $table->string('discipline', 20); // Discipline: struktur/arsitektur/mep/elv/ict
            $table->date('planned_submit_date')->nullable();
            $table->string('status', 30)->default('belum_diajukan'); // DrawingStatus mirror
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->unique(['project_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eng_drawings');
    }
};
