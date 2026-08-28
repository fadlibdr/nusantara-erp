<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-ENG: pengajuan persetujuan material (SMS, FM-10-05/22). Same recorded-
 * fact decision columns as the drawing submittal — see 001310 for why there is
 * no Approvable cycle and why decided_at is a date.
 *
 * No supersede chain here: a material rejected or sent back is re-submitted as
 * a NEW SMS row (a fresh sheet with a fresh number), which is how the paper
 * daftar persetujuan material works — the register keeps every attempt.
 *
 * Cross-module: project_id → prj_projects, item_id → inv_items, created_by →
 * users; all indexed, none constrained (shared-ID contract §3). item_id is
 * nullable because a submittal often precedes the item master row — the brand
 * is being approved before anyone buys it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eng_material_submittals', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // SMS/{Y}/{RM}/{N4}
            $table->unsignedBigInteger('project_id'); // prj_projects — indexed, no FK
            $table->unsignedBigInteger('item_id')->nullable(); // inv_items — indexed, no FK
            $table->string('material_name', 200);
            $table->string('brand', 150)->nullable();
            $table->string('spec_reference', 200)->nullable();
            $table->boolean('sample_attached')->default(false);
            $table->date('submitted_at');
            $table->string('reviewer_party', 10); // ReviewerParty: mk/owner
            $table->string('decision', 30)->nullable(); // SubmittalDecision — null = menunggu keputusan
            $table->date('decided_at')->nullable();
            $table->text('notes')->nullable();
            // users — indexed, no FK. Nullable for seeded rows (see 001310).
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('project_id');
            $table->index('item_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eng_material_submittals');
    }
};
