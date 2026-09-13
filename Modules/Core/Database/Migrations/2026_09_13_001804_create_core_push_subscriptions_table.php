<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Langganan web push: satu baris per PERANGKAT (P-3e, T3e.2).
 *
 * Sebuah langganan Web Push (RFC 8030) adalah tiga nilai yang diberikan
 * peramban dan hanya berarti bersama-sama: `endpoint` (alamat URL milik
 * layanan push yang dipilih peramban itu sendiri — Chrome memilih FCM, Firefox
 * memilih Mozilla autopush, Safari memilih Apple; tidak satu pun dari mereka
 * kita pilih), dan dua kunci `p256dh` + `auth` yang dipakai MENGENKRIPSI isi
 * pesan untuk perangkat itu (aes128gcm, RFC 8291). Ketiganya milik peramban,
 * disimpan apa adanya, dan tidak satu pun dari ketiganya adalah rahasia MILIK
 * KITA: kunci privat VAPID tidak pernah menyentuh tabel ini — ia hanya ada di
 * .env (WebPushSetup).
 *
 * ENDPOINT DISIMPAN UTUH, DAN TIDAK PERNAH DIINDEKS. Endpoint FCM yang
 * sungguhan diukur 188 karakter pada 13 Sep 2026, dan spesifikasinya tidak
 * memberi batas apa pun; sementara varchar(190) utf8mb4 = 760 byte sudah
 * menyentuh batas indeks InnoDB. Jadi endpoint hidup di kolom `text` (tanpa
 * indeks, tidak bisa gagal karena panjang) dan IDENTITASNYA dibawa kolom
 * sendiri: `endpoint_hash` = sha256 heksadesimal 64 karakter, unik. Itulah
 * yang menjawab "peramban ini sudah terdaftar?" — dan karena ia unik, sebuah
 * peramban yang berlangganan ulang MEMPERBARUI barisnya, bukan menumpuk baris
 * kedua yang membuat orangnya menerima dua pemberitahuan yang sama.
 *
 * LABEL PERANGKAT, BUKAN User-Agent MENTAH. Yang dibutuhkan layar adalah
 * "Chrome di Android" supaya orangnya bisa mengenali perangkat mana yang
 * dicabutnya; menyimpan seluruh User-Agent berarti menyimpan sidik jari
 * peramban yang tidak dipakai satu baris kode pun (PushDeviceLabel
 * menurunkannya sekali, saat mendaftar, dan membuang sisanya).
 *
 * Blok lanjutan Core 001800–001899 (CONVENTIONS §2); 001800–001803 terpakai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_push_subscriptions', function (Blueprint $table): void {
            $table->id();

            // cascadeOnDelete: langganan adalah milik satu orang dan tidak
            // punya arti tanpa dia. Pengguna yang dihapus tidak boleh
            // meninggalkan endpoint yang masih dikirimi.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();

            // Kunci milik peramban, base64url apa adanya. p256dh = kunci publik
            // P-256 tak terkompresi (65 byte → 87 karakter); auth = 16 byte
            // rahasia bersama (22 karakter). Kolomnya dilebihkan, bukan dipaskan:
            // yang salah panjang harus ditolak pemeriksa masukan dengan kalimat,
            // bukan dipotong diam-diam oleh kolom.
            $table->string('p256dh', 255);
            $table->string('auth', 255);

            $table->string('device_label', 80)->nullable();

            // Kapan langganan ini terakhir BENAR-BENAR menerima (2xx dari
            // layanan push). Kosong = belum pernah; layar mengatakan itu apa
            // adanya, bukan menampilkan tanggal pendaftaran seolah-olah
            // pengiriman.
            $table->timestamp('last_success_at')->nullable();

            $table->timestamps();

            $table->index('user_id', 'core_push_subs_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_push_subscriptions');
    }
};
