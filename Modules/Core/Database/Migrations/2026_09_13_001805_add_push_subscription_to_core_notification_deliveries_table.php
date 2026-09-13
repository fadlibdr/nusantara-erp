<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom penghubung: baris kotak keluar mana milik PERANGKAT mana (P-3e, T3e.3).
 *
 * Keputusan bentuk baris paket ini adalah **satu baris per LANGGANAN** untuk
 * kanal webpush — bukan satu baris per orang seperti e-mail dan WhatsApp.
 * Alasannya ada di LAPORAN-PAKET-HM-P-3e §2; ringkasnya: seseorang bisa punya
 * tiga perangkat yang menjawab BERBEDA (satu 201, satu 410, satu timeout), dan
 * satu baris tidak bisa jujur tentang tiga jawaban. Dengan satu baris per
 * langganan, "Kirim ulang" mengulang ke perangkat yang gagal saja, dan kolom
 * `error` menyebut perangkat yang mana.
 *
 * TANPA foreign key, dengan sengaja. Baris pengiriman adalah RIWAYAT dan harus
 * hidup lebih lama daripada perangkatnya — 404/410 dari layanan push MENGHAPUS
 * langganannya (itu seluruh aturan T3e.3), sementara baris yang mencatat
 * kejadian itu justru yang harus tetap terbaca sesudahnya. `nullOnDelete` akan
 * mengosongkan penunjuknya dan membuat "perangkat yang mana" tidak terjawab;
 * `cascadeOnDelete` akan menghapus buktinya. Jadi id-nya dibiarkan menggantung
 * dan kodenya membacanya sebagai "perangkat sudah tidak terdaftar" — kalimat
 * yang sama yang menolak Kirim ulang.
 *
 * `recipient` baris webpush berisi LABEL perangkat ("Chrome di Android"), bukan
 * endpoint: kolom itu dibaca manusia di layar Pengiriman Notifikasi, dan
 * endpoint 188 karakter di sana tidak memberi tahu siapa pun apa pun.
 *
 * Blok lanjutan Core 001800–001899 (CONVENTIONS §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_notification_deliveries', function (Blueprint $table): void {
            $table->unsignedBigInteger('push_subscription_id')->nullable()->after('channel');
            $table->index('push_subscription_id', 'core_notif_deliveries_push_sub_idx');
        });
    }

    public function down(): void
    {
        Schema::table('core_notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex('core_notif_deliveries_push_sub_idx');
            $table->dropColumn('push_subscription_id');
        });
    }
};
