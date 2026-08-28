<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-ENG: transmittal (TRM) — the cover sheet proving which documents crossed
 * the table, header + lines.
 *
 * Lines morph to either submittal type (document_type/document_id — the
 * core_attachments precedent) or carry free text alone. The wire never sees a
 * class name: TransmittalService maps the vocabulary 'drawing_submittal' /
 * 'material_submittal' to classes, the AttachableDocuments lesson.
 *
 * received_by is a NAME (string), not an employee id: the person who signs a
 * tanda terima is the MK's document controller or the owner's site rep —
 * external people who are deliberately not users or employees here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eng_transmittals', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // TRM/{Y}/{RM}/{N4}
            $table->unsignedBigInteger('project_id'); // prj_projects — indexed, no FK
            $table->string('direction', 10); // TransmittalDirection: keluar/masuk
            $table->string('to_party', 200);
            $table->date('transmittal_date');
            $table->text('notes')->nullable();
            $table->string('received_by', 150)->nullable(); // nama penanda tangan tanda terima
            $table->dateTime('received_at')->nullable();
            $table->unsignedBigInteger('created_by'); // users — indexed, no FK
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('created_by');
        });

        Schema::create('eng_transmittal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transmittal_id')->constrained('eng_transmittals')->cascadeOnDelete();
            $table->nullableMorphs('document'); // DrawingSubmittal / MaterialSubmittal — null = baris teks bebas
            $table->string('description', 300);
            $table->string('remarks', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eng_transmittal_lines');
        Schema::dropIfExists('eng_transmittals');
    }
};
