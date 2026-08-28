<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-QC: the inspection CHECKLIST library — a template plus its butir. One
 * template is one hold-point sheet (code Q1..Q31, e.g. Q7 "Pengecoran kolom"),
 * belonging to one work package and one stage (before/during/after); its items
 * are the lines an inspector ticks ok/nok/na against on the day.
 *
 * `code` is the operator's own catalogue number (Q1..Q31), unique and typed,
 * NOT a HasDocumentNumber sequence — the same call AHSP makes: an SNI-style
 * domain code the quality office owns, not a document number the system mints.
 * That is why the whole library imports from one XLSX through document-import
 * (ImportableDocuments 'inspection-templates'), keyed on the code.
 *
 * Items are a line table: no softDeletes, cascade with the template
 * (CONVENTIONS §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_inspection_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique(); // Q1..Q31 — operator-owned
            $table->string('work_package', 150); // paket pekerjaan
            $table->string('stage', 20); // InspectionStage: before/during/after
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('qc_inspection_template_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('qc_inspection_templates')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('check_text', 300); // butir yang diperiksa
            $table->string('acceptance', 300); // kriteria keberterimaan
            $table->string('tolerance', 120)->nullable(); // toleransi, bila ada
            $table->timestamps();

            $table->index('template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_inspection_template_items');
        Schema::dropIfExists('qc_inspection_templates');
    }
};
