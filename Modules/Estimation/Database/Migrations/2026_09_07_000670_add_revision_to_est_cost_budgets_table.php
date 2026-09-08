<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi RAP (F-2 / T2.5) — bentuk yang sama dengan revisi baseline.
 *
 * prj_baselines sudah menjawab pertanyaan ini untuk RENCANA WAKTU sejak P8:
 * revision_no, superseded_at, superseded_by_id, dan sebuah `reason` yang wajib
 * pada revisi ≥ 1. Kolom-kolom di bawah memberi RENCANA BIAYA bentuk yang sama
 * persis, supaya orang yang sudah paham satu di antaranya tidak perlu belajar
 * yang kedua — dan supaya rantainya sama-sama APPEND-ONLY: menyetujui revisi N
 * hanya menulis dua kolom pada pendahulunya, tidak satu byte pun isinya.
 *
 * SEMUA BERBAWAAN 0/NULL, jadi setiap RAP yang sudah ada menjadi "revisi 0 yang
 * belum digantikan" — yaitu persis perilakunya hari ini. Itu bukan kebetulan,
 * itu syaratnya: RapRevisionTest membuktikan gerbang anggaran dan layar
 * portofolio menjawab angka yang identik untuk RAP yang tidak pernah direvisi.
 *
 * Blok Estimation 000600–000699 masih lapang (000660 terakhir dipakai), jadi
 * tidak ada blok lanjutan yang dibutuhkan di sini — bandingkan Finance 001500
 * di paket yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('est_cost_budgets', function (Blueprint $table): void {
            // 'revision', nama yang diminta ROADMAP F-2. prj_baselines memakai
            // 'revision_no'; keduanya dibiarkan apa adanya alih-alih salah satu
            // diganti — mengubah nama kolom pada tabel yang sudah dibaca lima
            // layar adalah risiko yang tidak dibayar oleh keseragaman ejaan.
            $table->unsignedInteger('revision')->default(0)->after('status');
            // RAP yang direvisi menjadi RAP ini. Dalam modul yang sama, jadi
            // constrained() boleh (CONVENTIONS §3) — tetapi TANPA cascade:
            // sebuah revisi harus tetap terbaca ketika pendahulunya dibuang.
            $table->unsignedBigInteger('revised_from_id')->nullable()->after('revision');
            $table->text('revision_reason')->nullable()->after('revised_from_id');
            // Dua kolom yang ditulis saat penerusnya DISETUJUI — dan tidak
            // pernah ada yang lain yang ditulis pada baris pendahulunya.
            $table->dateTime('superseded_at')->nullable()->after('revision_reason');
            $table->unsignedBigInteger('superseded_by_id')->nullable()->after('superseded_at');

            $table->index('revised_from_id');
            $table->index('superseded_by_id');
            // Kueri "RAP yang mengatur proyek ini": project_id + status +
            // superseded_at, tiga kolom yang dibaca setiap gerbang PO/SPK.
            $table->index(['project_id', 'status', 'superseded_at'], 'est_cost_budgets_governing_index');
        });
    }

    public function down(): void
    {
        Schema::table('est_cost_budgets', function (Blueprint $table): void {
            $table->dropIndex('est_cost_budgets_governing_index');
            $table->dropIndex(['revised_from_id']);
            $table->dropIndex(['superseded_by_id']);
            $table->dropColumn(['revision', 'revised_from_id', 'revision_reason', 'superseded_at', 'superseded_by_id']);
        });
    }
};
