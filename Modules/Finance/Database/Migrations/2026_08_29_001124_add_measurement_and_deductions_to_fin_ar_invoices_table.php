<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 — the owner claim: built from an opname, and carrying the three
 * deductions the Risalah Pembayaran has always had on paper (kriteria #5).
 *
 * measurement_id            the prj_progress_measurements row this claim was
 *                           assembled from. Nullable: a termin invoice billed
 *                           by percentage is still perfectly legal and is what
 *                           every existing invoice is.
 * is_advance                this invoice IS the uang muka. It changes the
 *                           CREDIT leg — Cr 2-1400 Pendapatan Diterima Dimuka
 *                           instead of revenue — because an advance is a
 *                           contract liability, not income, and because the
 *                           opname-based claims that follow bill the FULL value
 *                           of the work and recover the DP out of it. Under the
 *                           old termin-percentage model there was nothing to
 *                           recover (DP 20 % + progres 80 % = 100 %), so the
 *                           default false leaves every existing invoice, every
 *                           existing journal and the PSAK 115 engine untouched.
 * advance_recovery_amount   the proportional slice of the DP this claim pays
 *                           back — Dr 2-1400 — mirroring
 *                           AdvanceService::recoveryFor on the subcontractor
 *                           side, rupiah for rupiah.
 * penalty_amount /          denda keterlambatan, deducted by the owner. Manual
 * penalty_reason            (no schedule engine here pretends to compute it)
 *                           and the reason is MANDATORY whenever the amount is
 *                           non-zero: a deduction on a signed sheet with no
 *                           stated reason is exactly the cell PANDUAN §13.5
 *                           refuses to print.
 *
 * Forward-only and MySQL-safe on live data: four added columns with defaults,
 * one nullable string, one indexed nullable id. No existing row's meaning moves
 * (zeros and false state facts, not guesses), and no journal is restated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_ar_invoices', function (Blueprint $table): void {
            // prj_progress_measurements — cross-module, indexed, no constraint.
            $table->unsignedBigInteger('measurement_id')->nullable()->after('termin_id');
            $table->boolean('is_advance')->default(false)->after('description');
            $table->decimal('advance_recovery_amount', 18, 2)->default(0)->after('retention_withheld');
            $table->decimal('penalty_amount', 18, 2)->default(0)->after('advance_recovery_amount');
            $table->string('penalty_reason', 300)->nullable()->after('penalty_amount');

            $table->index('measurement_id');
        });
    }

    public function down(): void
    {
        Schema::table('fin_ar_invoices', function (Blueprint $table): void {
            $table->dropIndex(['measurement_id']);
            $table->dropColumn([
                'measurement_id', 'is_advance', 'advance_recovery_amount',
                'penalty_amount', 'penalty_reason',
            ]);
        });
    }
};
