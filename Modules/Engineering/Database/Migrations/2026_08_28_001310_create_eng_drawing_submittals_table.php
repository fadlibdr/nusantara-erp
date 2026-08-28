<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-ENG: pengajuan persetujuan shop drawing (SDS, FM-10-03) — one row is one
 * revision handed to the MK/Owner.
 *
 * decision/decided_at/notes are RECORDED FACTS typed from the stamped sheet
 * that came back — the MK is not a users row, so there is no Approvable cycle
 * here; maker-checker applies to the RECORDING instead (the recorder may not
 * be created_by — see DrawingSubmittalService). decided_at is a DATE because
 * that is what the stamp carries; inventing a clock time for it would be a
 * number nobody wrote.
 *
 * Revision replacement mirrors prj_project_baselines (BaselineService): a new
 * revision writes superseded_at + superseded_by_id onto its predecessor in the
 * same transaction — never a status flag, so an un-superseded row is provably
 * the current one.
 *
 * created_by → users, indexed, NO constraint (foreignId to users from another
 * module is forbidden — shared-ID contract §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eng_drawing_submittals', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // SDS/{Y}/{RM}/{N4}
            $table->foreignId('drawing_id')->constrained('eng_drawings');
            $table->string('revision', 10); // R0, R1, …
            $table->date('submitted_at');
            $table->string('reviewer_party', 10); // ReviewerParty: mk/owner
            $table->string('decision', 30)->nullable(); // SubmittalDecision — null = menunggu keputusan
            $table->date('decided_at')->nullable();
            $table->text('notes')->nullable(); // catatan stempel MK, verbatim
            // users — indexed, no FK. Nullable for rows a SEEDER writes (a
            // seeder is nobody); the API always fills it, and the maker-
            // checker guard on decision recording reads it.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_by');
            $table->index('superseded_by_id');
            $table->unique(['drawing_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eng_drawing_submittals');
    }
};
