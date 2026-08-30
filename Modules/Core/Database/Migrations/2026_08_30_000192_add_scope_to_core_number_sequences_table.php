<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 — token {PROJ}: satu counter per proyek untuk jenis dokumen yang mask-nya
 * memuat token itu. Kunci unik lama (type, year) menjadi (type, year, scope).
 *
 * scope adalah KODE PROYEK (prj_projects.code) untuk sequence yang dipagari
 * proyek, dan STRING KOSONG untuk sequence tanpa pagar — BUKAN null, dengan
 * sengaja: MySQL, SQLite, dan Postgres sama-sama memperlakukan NULL sebagai
 * selalu-berbeda di indeks unik, sehingga (PO, 2026, NULL) boleh muncul dua
 * kali dan dua counter PO tahun berjalan hidup berdampingan. '' berperilaku
 * sebagai nilai biasa di ketiganya, jadi invariannya tetap tegak.
 *
 * Kompatibel-mundur untuk data hidup: kolom ditambah dengan default '', maka
 * setiap baris lama menjadi baris ber-scope-kosong dan counternya berlanjut
 * byte-identik — tidak ada penomoran ulang, tidak ada reset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_number_sequences', function (Blueprint $table): void {
            // 40 = lebar prj_projects.code, nilai yang dirender token {PROJ}.
            $table->string('scope', 40)->default('')->after('year');
        });

        // Dua panggilan Schema::table terpisah: SQLite menulis ulang tabel per
        // alter, dan mencampur addColumn dengan dropUnique dalam satu batch
        // membuat indeks lama dicari pada tabel yang sudah berubah.
        Schema::table('core_number_sequences', function (Blueprint $table): void {
            $table->dropUnique(['type', 'year']);
            $table->unique(['type', 'year', 'scope']);
        });
    }

    public function down(): void
    {
        // Hanya aman bila tidak ada baris ber-scope; unique (type, year) akan
        // menolak duplikat dengan sendirinya bila ada.
        Schema::table('core_number_sequences', function (Blueprint $table): void {
            $table->dropUnique(['type', 'year', 'scope']);
            $table->unique(['type', 'year']);
        });

        Schema::table('core_number_sequences', function (Blueprint $table): void {
            $table->dropColumn('scope');
        });
    }
};
