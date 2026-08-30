<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 — revisi generik (D9) pada inspeksi mutu, pola DrawingSubmittal (lihat
 * komentar di migrasi prj_work_permits pendampingnya). Aditif; inspeksi lama =
 * revisi 0 hidup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qc_inspections', function (Blueprint $table): void {
            $table->unsignedSmallInteger('revision')->default(0)->after('status');
            $table->dateTime('superseded_at')->nullable()->after('revision');
            $table->unsignedBigInteger('superseded_by_id')->nullable()->after('superseded_at');
            $table->index('superseded_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('qc_inspections', function (Blueprint $table): void {
            $table->dropIndex(['superseded_by_id']);
            $table->dropColumn(['revision', 'superseded_at', 'superseded_by_id']);
        });
    }
};
