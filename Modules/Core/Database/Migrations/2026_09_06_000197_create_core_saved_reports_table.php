<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan Bebas yang DISIMPAN, dan dibagikan per peran (Fase 1 / P1-F).
 *
 * Sebuah laporan bebas adalah pertanyaan, bukan data: "nilai kontrak per jenis
 * lingkup per bulan tanda tangan". Menyusunnya butuh selusin klik, dan tanpa
 * tabel ini setiap orang menyusun ulang pertanyaan yang sama setiap Senin pagi
 * — atau, lebih buruk, menyusunnya sedikit berbeda dan mendapat angka yang
 * sedikit berbeda untuk rapat yang sama.
 *
 * `resource` ADALAH KOLOM, bukan bagian dari JSON `definition`. Izin sebuah
 * laporan tersimpan diturunkan dari resource-nya (ReportableResources memberi
 * `{prefix}.view`), dan menurunkannya berarti membaca kolom — bukan membongkar
 * JSON setiap baris pada setiap pemuatan daftar.
 *
 * `definition` TEXT + cast 'json', bukan kolom JSON MySQL: satu bentuk untuk
 * kedua driver, alasan yang sama dengan core_user_preferences.000195. Isinya
 * TIDAK bebas — ReportDefinition memvalidasinya terhadap registri setiap kali
 * ditulis DAN setiap kali dijalankan, karena registri berubah lebih cepat
 * daripada baris ini: sebuah kolom yang dicabut dari katalog harus membuat
 * laporan lama berkata "kolom X sudah tidak ada", bukan menjalankan kueri
 * dengan kolom yang tidak ada lagi.
 *
 * `shared_roles` menyimpan NAMA peran, dan ini tabel PERTAMA di sistem ini yang
 * menyebut peran sama sekali — jadi kedua arahnya baru dan pilihannya perlu
 * ditulis:
 *
 *  - **Nama, bukan id.** Id peran tidak bisa membawa FK: peran milik Iam dan
 *    tabel ini milik Core, dan CONVENTIONS §3 melarang FK lintas modul. Sebuah
 *    id yang perannya dihapus akan menggantung tanpa cascade dan tanpa tanda.
 *    Nama adalah yang SUDAH diseberangkan ke klien (`iam/auth/me` mengirim
 *    `roles: [nama]`) dan yang dibaca `$user->hasRole($name)` — yang tidak
 *    pernah melempar, sementara scope `User::role($name)` milik Spatie
 *    melempar RoleDoesNotExist untuk nama basi, yaitu 500 alih-alih daftar
 *    kosong.
 *  - **Basi hanya karena diganti nama, dan basinya TERLIHAT.** Penulisan
 *    memvalidasi setiap nama terhadap tabel peran yang hidup, jadi sebuah
 *    berbagi tidak pernah LAHIR basi; bila peran kemudian diganti namanya,
 *    daftar laporan menandai barisnya ("peran <x> sudah tidak ada") alih-alih
 *    diam-diam berhenti membagikannya kepada siapa pun.
 *
 * `user_id` — BUKAN `created_by`. SegregationOfDuties memindai kolom bernama
 * `created_by` di SELURUH tabel untuk menegakkan maker-checker; menamainya
 * begitu akan mendaftarkan laporan tersimpan ke dalam alur persetujuan tanpa
 * ada yang memintanya.
 *
 * Tanpa softDeletes: ini artefak milik satu orang, seperti core_user_preferences.
 * "Hapus" di sini berarti hapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_saved_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('resource', 64);
            $table->text('definition');
            $table->text('shared_roles')->nullable();
            $table->timestamps();

            // Dua laporan bernama sama di daftar satu orang tidak bisa
            // dibedakan olehnya. MySQL memakai prefix (user_id) untuk "laporan
            // saya", jadi FK di atas tidak butuh indeks kedua.
            $table->unique(['user_id', 'name']);

            // "Laporan mana yang mati kalau sebuah resource dicabut dari
            // katalog" — pertanyaan yang ditanyakan setiap kali registri
            // berubah, dan satu-satunya pembacaan yang tidak lewat user_id.
            $table->index('resource');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_saved_reports');
    }
};
