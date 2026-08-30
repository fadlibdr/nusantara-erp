<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 — revisi generik (D9), pola DrawingSubmittal: revisi adalah BARIS BARU;
 * pendahulunya distempel superseded_at + superseded_by_id — bukan flag status —
 * sehingga baris yang belum distempel terbukti hidup. Aditif dan aman untuk
 * data MySQL lama: izin yang sudah ada menjadi revisi 0 yang hidup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prj_work_permits', function (Blueprint $table): void {
            $table->unsignedSmallInteger('revision')->default(0)->after('status');
            $table->dateTime('superseded_at')->nullable()->after('revision');
            // Self-reference — indexed, tanpa FK, seperti eng_drawing_submittals.
            $table->unsignedBigInteger('superseded_by_id')->nullable()->after('superseded_at');
            $table->index('superseded_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('prj_work_permits', function (Blueprint $table): void {
            $table->dropIndex(['superseded_by_id']);
            $table->dropColumn(['revision', 'superseded_at', 'superseded_by_id']);
        });
    }
};
