<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Absensi masuk/pulang dari ponsel (F-4) — MAJU-SAJA.
 *
 * Setiap kolom nullable dan tidak ada backfill. Baris absensi yang sudah ada
 * ditulis kerani dari lembar kertas; kolom-kolom ini kosong untuknya, dan
 * kosong di sini berarti "tidak dicatat", BUKAN nol. Itu bukan kehalusan:
 * jarak 0 meter berarti "berdiri tepat di titik proyek", sedangkan NULL berarti
 * "tidak ada yang tahu di mana orang ini berada" — dua kalimat yang berlawanan,
 * dan satu-satunya cara membedakannya adalah tidak pernah menulis 0 untuk yang
 * kedua.
 *
 * Tiga pasangan kolom yang perlu penjelasan:
 *
 * - `*_at` vs `*_device_at`. Yang pertama adalah waktu SERVER saat catatan
 *   sampai; yang kedua adalah jam ponsel saat tombolnya ditekan. Keduanya
 *   disimpan karena antrean luring bisa mengirim catatan berjam-jam kemudian,
 *   sehingga waktu server sendirian berbohong tentang kapan orangnya datang —
 *   dan jam ponsel sendirian bisa dipalsukan siapa saja. Waktu server tetap
 *   yang otoritatif; waktu perangkat DICATAT, tidak pernah MENGGANTIKAN.
 *
 * - `*_project_id`. Proyek yang dipakai sebagai acuan pengukuran jarak,
 *   disimpan terpisah dari `project_id` baris. Kerani boleh memindahkan baris
 *   ke proyek lain sesudahnya; kalau acuannya tidak ikut tersimpan, jarak yang
 *   sudah tercatat diam-diam berubah arti menjadi jarak ke lokasi yang salah.
 *
 * - `*_geofence_m`. Ambang yang BERLAKU saat itu, distempel seperti kebijakan
 *   persetujuan F-1. Tanpa stempel, menaikkan ambang di layar Pengaturan
 *   membersihkan setiap penanda "di luar lokasi" di masa lalu secara surut.
 *
 * Sengaja TIDAK ada kolom `outside_geofence`: ia turunan penuh dari
 * (`distance_m`, `geofence_m`) dan kolom turunan yang disimpan adalah kolom
 * yang suatu hari melenceng dari sumbernya. Model menghitungnya, dan
 * mengembalikan null bila salah satu sisinya null.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $sides = ['check_in', 'check_out'];

    public function up(): void
    {
        Schema::table('hr_attendances', function (Blueprint $table): void {
            foreach ($this->sides as $side) {
                $table->dateTime("{$side}_at")->nullable();
                $table->dateTime("{$side}_device_at")->nullable();
                $table->decimal("{$side}_latitude", 10, 7)->nullable();
                $table->decimal("{$side}_longitude", 10, 7)->nullable();
                $table->unsignedInteger("{$side}_accuracy_m")->nullable();
                // Lintas modul: indeks, tanpa FK (CONVENTIONS §3).
                $table->unsignedBigInteger("{$side}_project_id")->nullable();
                $table->unsignedInteger("{$side}_distance_m")->nullable();
                $table->unsignedInteger("{$side}_geofence_m")->nullable();
                // core_attachments.id milik selfie. Tanpa FK: lampiran dihapus
                // lewat AttachmentService, dan absensinya tetap catatan yang sah
                // tanpa fotonya.
                $table->unsignedBigInteger("{$side}_attachment_id")->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendances', function (Blueprint $table): void {
            foreach ($this->sides as $side) {
                $table->dropColumn([
                    "{$side}_at",
                    "{$side}_device_at",
                    "{$side}_latitude",
                    "{$side}_longitude",
                    "{$side}_accuracy_m",
                    "{$side}_project_id",
                    "{$side}_distance_m",
                    "{$side}_geofence_m",
                    "{$side}_attachment_id",
                ]);
            }
        });
    }
};
