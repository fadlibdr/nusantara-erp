<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 (kriteria #10, D12) — penanda sumber pada stock opname yang lahir dari
 * impor kartu stok warisan. Lihat migrasi Projects 2026_08_30_000745 untuk
 * alasan bentuknya (nullable, tanpa backfill, hanya ditulis mesin impor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_stock_adjustments', function (Blueprint $table): void {
            $table->string('import_source', 160)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('inv_stock_adjustments', function (Blueprint $table): void {
            $table->dropColumn('import_source');
        });
    }
};
