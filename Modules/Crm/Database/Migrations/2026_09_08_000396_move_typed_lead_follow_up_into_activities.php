<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tanggal tindak lanjut yang SUDAH DIKETIK, dipindahkan menjadi aktivitas.
 *
 * F-3 menjadikan `crm_leads.next_follow_up_at` TURUNAN dari aktivitas terbuka
 * (LeadFollowUpService). Tanpa migrasi ini, setiap tanggal yang pernah diketik
 * seseorang akan berubah makna dalam satu deploy: kolomnya masih memajang
 * "20 Agu 2026", sementara satu perubahan aktivitas pertama pada prospek itu
 * akan menghitung ulang dan MENGOSONGKANNYA — rencana yang hilang tanpa satu
 * baris pun yang bisa ditunjuk orang.
 *
 * Maka setiap prospek yang punya tanggal ketikan mendapat SATU aktivitas
 * terbuka bertanggal sama. Sesudahnya, turunannya sama persis dengan angka yang
 * sudah tertulis di layar hari ini — dipaku LeadFollowUpDerivationTest, yang
 * membandingkan keduanya baris per baris.
 *
 * TIGA HAL YANG SENGAJA TIDAK DITEBAK.
 *
 *  - JENISNYA `note`. Kolom lama tidak pernah menyimpan apakah yang
 *    direncanakan telepon, kunjungan, atau email; memilih 'call' akan
 *    mengarang catatan pekerjaan yang tidak pernah ditulis siapa pun.
 *  - PEMILIKNYA KOSONG. Aktivitas ini pekerjaan baru, dan siapa yang
 *    menyanggupinya belum pernah dinyatakan. Kartunya berbunyi "Belum
 *    ditugaskan" — pemilik prospeknya tetap terbaca di layar prospek itu.
 *  - JAMNYA TIDAK ADA. due_at bertipe date, seperti kolom asalnya (000383):
 *    tidak ada 00:00 yang berpura-pura sebagai janji temu.
 *
 * IDEMPOTEN. Prospek yang sudah punya aktivitas terbuka bertanggal dilewati,
 * jadi migrate yang berjalan dua kali (deploy separuh, verifikasi ulang) tidak
 * menggandakan barisnya. Prospek terhapus IKUT dikonversi: kolomnya tetap
 * dibaca kalau baris itu dipulihkan, dan aturan "turunannya = angka yang sudah
 * tertulis" tidak boleh punya pengecualian yang tidak terlihat.
 *
 * TIDAK ADA down() YANG MENGHAPUS DATA. Membalik migrasi ini akan menghapus
 * baris yang bisa saja sudah disunting, ditandai selesai, atau ditugaskan ke
 * seseorang sesudah deploy — FORWARD-ONLY, aturan rumah.
 */
return new class extends Migration
{
    private const SUBJECT = 'Tindak lanjut berikutnya (dipindahkan dari kolom lama)';

    public function up(): void
    {
        if (! Schema::hasTable('crm_activities') || ! Schema::hasColumn('crm_leads', 'next_follow_up_at')) {
            return;
        }

        $now = now();

        DB::table('crm_leads')
            ->whereNotNull('next_follow_up_at')
            ->orderBy('id')
            ->select('id', 'next_follow_up_at')
            ->chunk(200, function ($leads) use ($now): void {
                foreach ($leads as $lead) {
                    $sudahPunya = DB::table('crm_activities')
                        ->where('document_type', 'lead')
                        ->where('document_id', $lead->id)
                        ->whereNull('done_at')
                        ->whereNull('deleted_at')
                        ->whereNotNull('due_at')
                        ->exists();

                    if ($sudahPunya) {
                        continue;
                    }

                    DB::table('crm_activities')->insert([
                        'document_type' => 'lead',
                        'document_id' => $lead->id,
                        'type' => 'note',
                        'subject' => self::SUBJECT,
                        // Sepuluh karakter pertama: kolom bercast date bisa
                        // tersimpan sebagai "2026-08-20 00:00:00" di SQLite,
                        // dan jam palsu itu tidak boleh ikut pindah.
                        'due_at' => substr((string) $lead->next_follow_up_at, 0, 10),
                        'notes' => 'Dibuat otomatis saat paket F-3 menjadikan tanggal tindak lanjut prospek turunan '
                            .'dari aktivitas. Jenis dan pemiliknya tidak diketahui kolom lama, jadi tidak ditebak.',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Sengaja kosong — lihat docblock. Baris aktivitasnya milik penggunanya
        // sejak menit pertama sesudah deploy.
    }
};
