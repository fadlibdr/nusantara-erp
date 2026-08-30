<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 (kriteria #10, D12) — penanda sumber pada SP3 Induk yang lahir dari impor
 * lembar Opname/SP3 warisan. Lihat migrasi Projects 2026_08_30_000745 untuk
 * alasan bentuknya (nullable, tanpa backfill, hanya ditulis mesin impor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scm_labor_contracts', function (Blueprint $table): void {
            $table->string('import_source', 160)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('scm_labor_contracts', function (Blueprint $table): void {
            $table->dropColumn('import_source');
        });
    }
};
