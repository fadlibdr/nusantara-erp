<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-3d — langganan webhook keluar dan LOG PENGIRIMANNYA (Core 001803).
 *
 * Blok Core karena `DocumentTransitioned` tinggal di Core dan dipancarkan dua
 * belas jenis dokumen dari sembilan modul; sebuah tabel langganan yang duduk di
 * salah satu modul fitur akan menjadi tabel yang di-import modul lain untuk
 * DATA — yang dilarang CONVENTIONS §1.
 *
 * TANPA FOREIGN KEY, mengikuti `fin_bank_inbox_files` (P-3c) dan
 * `core_notification_deliveries`: baris log adalah SEJARAH, dan sejarah tidak
 * boleh ikut terhapus ketika langganannya dicabut. Yang menghubungkan keduanya
 * adalah `subscription_id` berindeks, dan layar log menampilkan nama langganan
 * yang DIBEKUKAN di barisnya sendiri untuk baris yang langganannya sudah hilang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_webhook_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            // 500: URL penerima milik orang lain, dan panjangnya bukan urusan kita.
            $table->string('url', 500);
            // Teks terenkripsi (cast 'encrypted' di model) — panjangnya jauh di
            // atas 255 sesudah enkripsi Laravel, jadi text dan bukan string.
            $table->text('secret');
            $table->timestamp('secret_set_at')->nullable();
            // Daftar peristiwa yang dilanggan: document.submitted|approved|rejected.
            $table->text('events');
            // null = SETIAP jenis dokumen. Daftar = hanya slug yang disebut.
            $table->text('document_types')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('core_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('subscription_id')->index();
            // Nama langganan DIBEKUKAN: baris log yang langganannya sudah
            // dicabut tetap bisa menyebut ke mana ia dikirim.
            $table->string('subscription_name', 100);
            $table->string('url', 500);
            // uuid peristiwa — penerima memakainya untuk menolak kiriman ganda.
            $table->string('event_id', 36)->index();
            $table->string('event', 40);
            $table->string('document_type', 60);
            $table->unsignedBigInteger('document_id');
            $table->string('document_code', 40)->nullable();
            // BYTE YANG DITANDATANGANI, apa adanya. Bukan array yang di-encode
            // ulang saat ditampilkan: tanda tangan hanya bisa diperiksa ulang
            // terhadap byte yang sama persis (perangkap D).
            $table->text('payload');
            // CATATAN percobaan TERAKHIR, bukan janji yang dibuat saat
            // mengantre: nilai header yang benar-benar berangkat, ditulis
            // tepat sebelum POST-nya (V-webhook-1). null selama baris ini
            // belum pernah dicoba.
            $table->string('signature', 120)->nullable();
            $table->string('status', 12)->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_webhook_deliveries');
        Schema::dropIfExists('core_webhook_subscriptions');
    }
};
