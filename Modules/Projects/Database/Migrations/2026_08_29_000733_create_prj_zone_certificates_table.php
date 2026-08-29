<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 — BAPP per zona (berita acara pemeriksaan pekerjaan), the sheet the MK
 * walks a floor with and marks Selesai / Diperiksa / Nunggu perbaikan.
 *
 * NOT Approvable, on the same reading NCR is not: nobody submits a BAPP into
 * existence and nobody approves it into truth — an inspector walked the zone
 * and wrote down what he saw, and certified_by_party records which side of the
 * table he sat on. Its status is its own three-value enum.
 *
 * NO UNIQUE (project_id, location_id), on purpose. A zone is inspected more
 * than once: BAPP I says "nunggu perbaikan", the repair happens, BAPP II says
 * "selesai". Collapsing that into one editable row would erase the first sheet,
 * which is exactly the evidence the second one rests on. The zone's CURRENT
 * status is therefore the LATEST certificate — certified_at, then id — and both
 * readers (the 'done' gate and the owner-claim gate of kriteria #6) ask
 * ZoneCertificateService for it rather than computing it themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_zone_certificates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // BAPP/{Y}/{RM}/{N4}
            $table->foreignId('project_id')->constrained('prj_projects')->cascadeOnDelete();
            $table->unsignedBigInteger('location_id'); // core_locations — indexed, no constraint
            // done | check | waiting_repair — ZoneCertificateStatus.
            $table->string('status', 30)->default('check');
            $table->date('certified_at')->nullable();
            // mk | owner | kontraktor — WHO signed off, never derived from
            // project master data (roadmap §7: a signature column is filled by
            // a recorded decision or it stays blank).
            $table->string('certified_by_party', 30)->nullable();
            $table->string('certified_by_name', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'location_id']);
            $table->index('status');
            $table->index('certified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_zone_certificates');
    }
};
