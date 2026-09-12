<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Nomor WhatsApp pengguna + opt-in BERSTEMPEL WAKTU (P-3a, T3a.3).
 *
 * users tidak pernah punya kolom telepon. Tiga kolom, semuanya nullable dan
 * aditif, tanpa backfill:
 *
 *   phone_e164            "+6281234567890" — E.164 ketat (PhoneNumber): '+',
 *                         8–15 digit, tanpa spasi/strip; disimpan ternormalisasi.
 *   whatsapp_opt_in_at    KAPAN orangnya setuju menerima pesan WhatsApp dari
 *                         ERP ini. Persetujuan yang tidak bertanggal bukan
 *                         persetujuan — karena itu bukan boolean.
 *   whatsapp_opt_in_via   LEWAT APA: 'profil' (orangnya sendiri di Profil ›
 *                         Notifikasi) atau 'admin' (administrator mencatat
 *                         persetujuan yang diberikan di luar aplikasi, di
 *                         Sistem › Pengguna).
 *
 * Persetujuan melekat pada NOMOR: mengganti nomor mengosongkan keduanya
 * kecuali opt-in dinyatakan lagi pada saat yang sama (WhatsAppConsent).
 * Tanpa nomor → skipped "tidak punya nomor"; tanpa stempel → skipped "belum
 * opt-in" (DeliveryGate). Blok Iam 000200–000299; terakhir 000251.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone_e164', 16)->nullable()->after('email');
            $table->timestamp('whatsapp_opt_in_at')->nullable()->after('phone_e164');
            $table->string('whatsapp_opt_in_via', 16)->nullable()->after('whatsapp_opt_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['phone_e164', 'whatsapp_opt_in_at', 'whatsapp_opt_in_via']);
        });
    }
};
