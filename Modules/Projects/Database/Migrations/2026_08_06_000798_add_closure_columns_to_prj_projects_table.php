<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who closed the project, when, and what was still open at that instant.
 *
 * 'Ditutup' used to be one option in a free dropdown: no record of the click,
 * no record of what it stepped over — PRJ-2026-001 could be closed with termin
 * 4 "BAST 15%" (Rp 7,275 M) and termin 5 retensi (Rp 2,425 M) unbilled and the
 * row would look identical to a project closed clean. The 'Tutup proyek' action
 * writes all four columns; a BAST II approval writes the first two and leaves
 * the snapshot to its own prerequisite_snapshot on prj_bast, which already
 * answers "what was true" for that path.
 *
 * No backfill: on the live demo file no project has status='closed', so there
 * is no historical closure to reconstruct — and inventing closed_at for one
 * would be exactly the fabrication FORWARD-ONLY forbids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prj_projects', function (Blueprint $table): void {
            $table->dateTime('closed_at')->nullable()->after('status');
            // users.id — app-owned, no DB constraint, matching the module's
            // other actor columns (created_by, prerequisite_override_by).
            $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');
            // The checklist verbatim, as evaluated at the moment of closing.
            $table->json('closure_snapshot')->nullable()->after('closed_by');
            // Set only when a WARNING was actually lifted — blocks can never be
            // talked past, so a reason here always corresponds to an item the
            // closer saw open and decided to accept.
            $table->text('closure_override_reason')->nullable()->after('closure_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('prj_projects', function (Blueprint $table): void {
            $table->dropColumn([
                'closed_at',
                'closed_by',
                'closure_snapshot',
                'closure_override_reason',
            ]);
        });
    }
};
