<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 — WHERE a week's realisasi came from, written on the row itself.
 *
 * Until now prj_weekly_progress.actual_pct was always a hand-typed percentage
 * and EvmService labelled the whole curve 'weekly_report' unconditionally. P3
 * makes an APPROVED OPNAME the preferred source — value-weighted over the BOQ,
 * which is a measurement rather than an estimate — and keeps the manual number
 * for weeks no opname covers.
 *
 * Two sources means the label has to be data, not a constant. The SPA and the
 * print layer both read it, and a curve that says 'weekly_report' while the
 * number behind it came from an opname (or the reverse) is precisely the kind
 * of plausible-looking cell PANDUAN §13.5 forbids.
 *
 * Default 'weekly_report' and NOT backfilled: every existing row genuinely was
 * typed in by a supervisor, so the default states a fact rather than guessing
 * one. MySQL-safe on live data — one nullable-free string column with a
 * default, no index, no rewrite of existing values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prj_weekly_progress', function (Blueprint $table): void {
            // weekly_report | progress_measurement
            $table->string('actual_pct_source', 30)->default('weekly_report')->after('actual_pct');
        });
    }

    public function down(): void
    {
        Schema::table('prj_weekly_progress', function (Blueprint $table): void {
            $table->dropColumn('actual_pct_source');
        });
    }
};
