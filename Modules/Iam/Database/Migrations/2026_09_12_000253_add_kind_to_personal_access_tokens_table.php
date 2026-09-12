<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-3d — satu kolom yang memisahkan token SESI SPA dari token PRIBADI.
 *
 * BLOK IAM, BUKAN CORE. `personal_access_tokens` adalah tabel AKAR (migrasi
 * `2026_07_25_000001`, milik Sanctum), jadi tidak ada modul yang "memilikinya"
 * menurut CONVENTIONS §2. Yang menentukan pilihan adalah preseden: Iam sudah
 * memperluas tabel identitas akar tiga kali — `users` lewat 000210 (kolom ERP),
 * 000250 (onboarding) dan 000252 (telepon/opt-in WhatsApp). Kredensial seorang
 * pengguna duduk di samping barisnya sendiri. Core 001803 disimpan untuk tabel
 * webhook, yang memang milik Core karena `DocumentTransitioned` tinggal di sana.
 *
 * ADITIF, NULLABLE, TANPA BACKFILL. Setiap baris yang sudah ada di produksi
 * hari ini adalah token yang dibuat `AuthController::login`, dan `kind` NULL
 * dibaca `ApiToken::isPersonal()` sebagai token sesi — jadi plafon global 720
 * menit tetap berlaku baginya dan tidak satu pun menjadi abadi. Lihat
 * `tests/Feature/Iam/ApiTokenExpiryTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            // 16 karakter: 'session' (7) dan 'personal' (8) dengan ruang sisa.
            // Tanpa indeks — tidak ada kueri yang menyaring dengannya; daftar
            // token di layar Profil menyaring dengan tokenable_id, yang sudah
            // terindeks lewat morphs().
            $table->string('kind', 16)->nullable()->after('abilities');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
