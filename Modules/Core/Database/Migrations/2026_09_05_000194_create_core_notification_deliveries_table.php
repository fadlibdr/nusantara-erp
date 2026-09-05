<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kotak keluar pengiriman notifikasi (Fase 0 / P-0b, T0b.3).
 *
 * core_notifications adalah kanal kebenaran (dalam aplikasi, sinkron, selalu).
 * Tabel ini mencatat setiap upaya menyampaikan baris itu lewat kanal LUAR —
 * hari ini e-mail, nanti WhatsApp dan web push (Fase 3) — dengan satu aturan:
 * pengiriman yang gagal harus TERLIHAT. Sebelum ini Mail::to()->send() dibungkus
 * guard() yang mencatat ke log lalu diam; dari layar tidak ada bedanya antara
 * "terkirim", "gagal", dan "e-mail memang dimatikan".
 *
 *   queued   baris ditulis, job DeliverNotification di-dispatch (atau dispatch-nya
 *            gagal — baris tetap ada, tombol Kirim ulang yang memperbaikinya)
 *   sent     penyedia menerima; provider_id = Message-ID bila ada
 *   failed   5 percobaan habis (backoff 60/300/900/3600 s); error = pesan penyedia
 *   skipped  tidak pernah dicoba, dengan alasan di error: e-mail dimatikan di
 *            Pengaturan, atau penerima tidak punya alamat
 *
 * channel dan status string, bukan enum kolom: ->change() pada enum menghapus
 * atribut kolom di Laravel 12 (risiko ROADMAP-HASHMICRO P-0) dan Fase 3 akan
 * menambah kanal. Nilai sahnya di NotificationDelivery::CHANNELS / STATUSES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_id')->constrained('core_notifications')->cascadeOnDelete();
            $table->string('channel', 16);            // email|whatsapp|webpush
            $table->string('recipient', 190);         // alamat e-mail / nomor E.164 / endpoint push
            $table->string('status', 12);             // queued|sent|failed|skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_id', 190)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            // Layar Pengiriman Notifikasi menyaring per status; pekerja/penyapu
            // menanyakan "queued yang sudah waktunya".
            $table->index(['status', 'next_attempt_at']);
            // MySQL membuat indeks untuk FK di atas dengan sendirinya; SQLite
            // tidak, dan cascade delete dari core_notifications mencarinya.
            // Bercabang per driver (pola T0.2) supaya MySQL tidak memikul indeks
            // ganda pada kolom yang sama.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->index('notification_id', 'core_notif_deliveries_notification_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_notification_deliveries');
    }
};
