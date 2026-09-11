<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunci template per peristiwa pada notifikasi (P-3a, T3a.1).
 *
 * `event` (document.submitted|approved|rejected|system.alert) menyebut JENIS
 * baris untuk kotak masuk dan dedupe — mengubah nilainya akan mematahkan
 * keduanya. Yang dibutuhkan kanal luar adalah hal lain: TEMPLATE mana yang
 * dipakai (surel per peristiwa; template WhatsApp yang disetujui Meta), dan
 * hanya lima peristiwa operasional yang punya: deadline.due,
 * approval.escalated, ar.dunning, backup.stale, scheduler.down
 * (NotificationTemplates). NULL = tidak punya → template umum yang sudah ada
 * (ApprovalNotificationMail) untuk surel, dan `skipped` "peristiwa ini tidak
 * punya template WhatsApp" untuk WhatsApp — dikatakan, bukan diam-diam.
 *
 * Di baris NOTIFIKASI, bukan di baris pengiriman: satu fakta, satu tempat;
 * baris pengiriman adalah salinan per kanal dan membacanya lewat relasi.
 * Nullable, tanpa backfill — baris lama memang tidak punya template.
 * Blok lanjutan Core 001800–001899 (CONVENTIONS §2); 001800 dipakai F-8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_notifications', function (Blueprint $table): void {
            $table->string('template', 40)->nullable()->after('event');
        });
    }

    public function down(): void
    {
        Schema::table('core_notifications', function (Blueprint $table): void {
            $table->dropColumn('template');
        });
    }
};
