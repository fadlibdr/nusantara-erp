<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PP 35/2021 permits a PKWT with NO calendar end date: berdasarkan selesainya
 * suatu pekerjaan tertentu (Pasal 5, 9) — common for per-project construction
 * crews. With only pkwt_end_date on the row, NULL is ambiguous: it can mean
 * "jangka waktu, date never entered" (the compliance gap worth nagging —
 * EMP-0007 Joko Susilo and EMP-0008 Made Wirawan are exactly this) or
 * "selesainya pekerjaan, lawfully dateless" (nothing to nag; pkwt_end_date
 * then holds the Pasal 9 estimate, if one is recorded). Left ambiguous, the
 * register incentivises inventing a fake date just to silence the watcher.
 *
 * pkwt_basis records which shape a kontrak row is. Nullable on purpose: all
 * legacy rows stay NULL, and the deadline watcher treats NULL as jangka_waktu
 * so its day-one missing-date alarms keep firing until HR states otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table): void {
            // PkwtBasis: jangka_waktu|selesainya_pekerjaan
            $table->string('pkwt_basis', 30)->nullable()->after('employment_type');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table): void {
            $table->dropColumn('pkwt_basis');
        });
    }
};
