<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status balik dari penyedia lewat webhook (P-3a, T3a.3).
 *
 * `status` baris adalah kebenaran KITA (queued|sent|failed|skipped): `sent`
 * berarti penyedia MENERIMA pesan dan memberi pengenal (wamid). Apa yang
 * terjadi sesudahnya — sampai ke perangkat, dibaca, atau gagal di jalan —
 * diberitahukan Meta lewat webhook bertanda tangan, dan itulah dua kolom
 * ini: provider_status (sent|delivered|read|failed, kosakata Meta apa
 * adanya) dan kapan. Dipisah dari `status` supaya "diterima penyedia" dan
 * "sampai ke orangnya" tidak pernah tertukar; webhook `failed` juga menandai
 * `status`=failed karena pesan itu memang tidak sampai.
 *
 * Indeks provider_id: webhook mencari baris menurut wamid, dan tanpa indeks
 * setiap status Meta adalah pemindaian penuh tabel yang tumbuh setiap hari.
 * varchar(190) utf8mb4 = 760 byte, di bawah batas indeks InnoDB.
 * Blok lanjutan Core 001800–001899; 001801 dipakai T3a.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_notification_deliveries', function (Blueprint $table): void {
            $table->string('provider_status', 16)->nullable()->after('provider_id');
            $table->timestamp('provider_status_at')->nullable()->after('provider_status');
            $table->index('provider_id', 'core_notif_deliveries_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::table('core_notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex('core_notif_deliveries_provider_idx');
            $table->dropColumn(['provider_status', 'provider_status_at']);
        });
    }
};
