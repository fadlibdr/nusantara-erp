<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 — BAST SUBKON I/II, shaped after prj_bast and gated by its own two
 * prerequisites.
 *
 * The owner side has had a handover document since the first release; the
 * subcontract side had only `defect_liability_until`, a date with no sheet
 * behind it. That is the wrong way round for the party whose retention we are
 * holding: BAST I is what STARTS his masa pemeliharaan (and therefore the
 * clock RetentionService::assertDefectLiabilityOver measures), and BAST II is
 * what ends it.
 *
 * THE TWO PREREQUISITES (HandoverService::assertPrerequisites), both hard:
 *
 *   the last opname is approved   a handover certifies that the work is done,
 *       and an opname still sitting in draft or submitted is the subcontractor
 *       claiming work whose measurement nobody has accepted. Signing BAST I
 *       over it hands him the retention clock while the volume is still in
 *       dispute.
 *   retention not yet released    for BAST I only. Retention is released after
 *       the maintenance period BAST I begins; a release that already happened
 *       means either the period is over (so this is BAST II, not I) or the
 *       money left early, and back-dating a BAST I over it would paper the
 *       second case over as the first.
 *
 * Approvable, because unlike the BAPP this one is OUR signature releasing OUR
 * leverage: prj_bast walks submit → approve for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scm_handovers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // BSK/{Y}/{RM}/{N4}
            $table->foreignId('subcontract_id')->constrained('scm_subcontracts');
            $table->string('handover_type', 10); // bast1 | bast2 — HandoverType
            $table->date('handover_date');
            // When the retention of THIS SPK becomes releasable — the BAST I
            // date plus the SPK's maintenance period, published by approval the
            // way prj_bast publishes retention_release_due.
            $table->date('retention_release_due')->nullable();
            $table->text('scope_notes')->nullable();
            $table->string('handed_over_by', 150)->nullable(); // wakil subkon yang menyerahkan
            $table->string('received_by', 150)->nullable();    // wakil kami yang menerima
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            // One LIVE BAST I and one LIVE BAST II per SPK — enforced in
            // HandoverService, not by a unique index, for the reason migration
            // 000721 spells out: a soft-deleted row keeps occupying its slot in
            // a full index and this application has no undelete (PANDUAN §14),
            // so a deleted-then-redrafted BAST would 500 forever. The partial
            // index that fixes it is SQLite-only syntax; the service check is
            // portable, and it is the only one that can answer in Indonesian.
            $table->index(['subcontract_id', 'handover_type']);
            $table->index('status');
            $table->index('handover_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scm_handovers');
    }
};
