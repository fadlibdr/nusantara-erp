<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 — ast_assets.ownership enum owned|rented (menutup Laporan Deviasi v2
 * §3.5 "alat sewa tak bisa masuk ast_assets" dan §3.6 "milik sendiri saja").
 *
 * BACKFILL: kolom ditambahkan dengan default 'owned', sehingga setiap aset
 * yang sudah ada — semuanya dibeli, karena sebelum migrasi ini register tidak
 * bisa menampung alat sewa — menjadi owned. Ini restatement fakta yang sudah
 * dibawa barisnya (pola backfill vendor_type P4, migrasi 000867), bukan fakta
 * akuntansi baru, dan itulah yang membuatnya sah di bawah aturan forward-only.
 *
 * TIGA KOLOM DILONGGARKAN ke nullable — perubahan kolom pertama di repo ini,
 * memakai change() native Laravel 12 (MySQL: ALTER TABLE MODIFY; SQLite:
 * rebuild tabel yang mempertahankan data — diverifikasi terhadap salinan
 * database.sqlite berisi data sebelum di-merge):
 *
 *   acquisition_date, acquisition_cost — alat sewa tidak pernah dibeli, jadi
 *       keduanya tidak punya nilai yang jujur. NULL berarti "tidak ada",
 *       bukan "nol rupiah": Rp 0 akan ikut terhitung di depreciableBase(),
 *       kartu aset, dan neraca sebagai angka, padahal ia bukan angka.
 *   book_value — nilai buku alat sewa adalah NULL (bergaris di layar dan
 *       cetakan), bukan 0: alat itu tidak pernah ada di neraca kita, sehingga
 *       "nilai bukunya Rp 0" adalah pernyataan akuntansi yang salah arah.
 *       Model, Resource, dan AssetRegisterService menjaga NULL ini tetap NULL.
 *
 * Aset owned TIDAK berubah bentuk: request-nya tetap mewajibkan ketiganya,
 * jadi kelonggaran skema tidak pernah sampai ke operator aset beli.
 *
 * Kolom sewa (vendor_id → prc_vendors lintas-modul, bare id + index sesuai
 * CONVENTIONS §3; rental_rate; rate_basis per_bulan|per_hari_8jam|per_jam;
 * rental_start/rental_end) hanya terisi untuk rented — divalidasi request,
 * bukan constraint DB, pola yang sama dengan kolom disposal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ast_assets', function (Blueprint $table): void {
            $table->string('ownership', 10)->default('owned')->after('serial_no');
            $table->unsignedBigInteger('vendor_id')->nullable()->after('ownership'); // prc_vendors.id (lessor), lintas-modul tanpa FK
            $table->decimal('rental_rate', 18, 2)->nullable()->after('vendor_id');
            $table->string('rate_basis', 20)->nullable()->after('rental_rate'); // per_bulan | per_hari_8jam | per_jam
            $table->date('rental_start')->nullable()->after('rate_basis');
            $table->date('rental_end')->nullable()->after('rental_start');

            $table->index('ownership');
            $table->index('vendor_id');
        });

        Schema::table('ast_assets', function (Blueprint $table): void {
            $table->date('acquisition_date')->nullable()->change();
            $table->decimal('acquisition_cost', 18, 2)->nullable()->change();
            $table->decimal('book_value', 18, 2)->nullable()->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ast_assets', function (Blueprint $table): void {
            $table->date('acquisition_date')->nullable(false)->change();
            $table->decimal('acquisition_cost', 18, 2)->nullable(false)->change();
            $table->decimal('book_value', 18, 2)->nullable(false)->default(0)->change();
        });

        Schema::table('ast_assets', function (Blueprint $table): void {
            $table->dropIndex(['ownership']);
            $table->dropIndex(['vendor_id']);
            $table->dropColumn(['ownership', 'vendor_id', 'rental_rate', 'rate_basis', 'rental_start', 'rental_end']);
        });
    }
};
