<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferensi per pengguna yang MENGIKUTI ORANGNYA (Fase 1 / P1-C, T1C.1).
 *
 * Sampai P1-B favorit, "Terakhir dibuka" dan kepadatan hidup di localStorage
 * berkunci id pengguna (`nusantara_erp_fav:<id>`, `…_recent:<id>`,
 * `…_density:<id>`). Itu benar untuk tablet kantor lapangan yang dipakai
 * bergantian — kasir tidak mewarisi lima dokumen pengawas — tetapi salah untuk
 * ORANGNYA: bintang yang dipasang di desktop kantor tidak ada di tablet, dan
 * peramban yang dibersihkan menghapus semuanya tanpa jejak. Tabel ini
 * memindahkan keputusan itu ke server; localStorage tetap dipakai sebagai
 * CERMIN (prefs.js) supaya kepadatan terpasang sebelum shell digambar dan
 * aplikasi tetap bisa dibaca saat luring.
 *
 * `key` bukan kolom bebas: whitelist-nya ada di Modules\Core\Support\
 * UserPreferences (validator + batas ukuran per kunci, plafon keras 16 KB per
 * nilai). Kunci di luar daftar dijawab 422 dengan nama kuncinya — bukan
 * disimpan diam-diam, karena sebuah baris yang tidak pernah dibaca siapa pun
 * adalah data pengguna yang kita simpan tanpa alasan.
 *
 * value TEXT berisi JSON, bukan kolom JSON MySQL: nilainya kadang SKALAR
 * ('compact'), dan MySQL 8 menerima skalar JSON sementara SQLite hanya melihat
 * teks — satu bentuk untuk kedua driver, di-cast di model. Batas 16 KB dijaga
 * registri, jauh di bawah 65.535 byte TEXT.
 *
 * UNIQUE(user_id, key): satu baris per orang per kunci; PUT menimpa
 * (updateOrCreate), tidak pernah menumpuk riwayat — preferensi bukan jurnal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_user_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key', 64);
            $table->text('value');
            $table->timestamps();

            // Kunci baca satu-satunya: "semua preferensi saya" dan "preferensi
            // saya untuk kunci ini". Indeks unik ini melayani keduanya, dan
            // MySQL memakai prefix (user_id) untuk yang pertama — tidak ada
            // indeks kedua yang perlu dibuat untuk FK di atas.
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_user_preferences');
    }
};
