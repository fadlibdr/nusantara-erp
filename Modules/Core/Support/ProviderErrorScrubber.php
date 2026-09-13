<?php

namespace Modules\Core\Support;

use Illuminate\Support\Str;

/**
 * Saring jawaban penyedia SEBELUM ia menjadi kolom error kotak keluar,
 * pesan pengecualian, atau baris log (P-3a, perangkap E).
 *
 * Jawaban HTTP penyedia bisa memuat token di URL ("…?access_token=EAAB…"),
 * header Bearer yang ikut tercetak pengecualian klien, atau nomor telepon
 * penerima (dan penerima LAIN, pada galat batch). Kolom error 500 karakter
 * dibaca setiap pemegang core.update di layar Pengiriman Notifikasi — dan
 * ikut ke setiap backup. Maka:
 *
 *   1. setiap rahasia yang dikenal (WhatsAppSetup::secrets()) → [rahasia]
 *   2. "Bearer <apa pun>"                                     → Bearer [rahasia]
 *   3. access_token=… / token=… / app_secret=… / secret=…     → …=[rahasia]
 *   4. query string URL apa pun                               → ?[…]
 *   5. deretan 8–15 digit (dengan/tanpa +)                    → [nomor]
 *   6. keluarannya DIPAKSA menjadi UTF-8 sah
 *   7. dipotong 480 karakter
 *
 * Kode galat Meta (mis. 131026) ≤ 6 digit dan tidak tersentuh aturan 5;
 * wamid bukan angka. Yang hilang dari pesan hanya yang tidak boleh ada.
 *
 * ATURAN 6 ADALAH SYARAT AGAR KOLOMNYA BISA DITULIS SAMA SEKALI (V-webhook-3).
 * Badan jawaban penyedia tidak wajib berupa teks: sebuah halaman galat
 * windows-1252 dengan huruf beraksen, atau badan ter-gzip yang tidak
 * di-dekode, adalah byte yang bukan UTF-8 sah. Di SQLite byte itu tersimpan
 * diam-diam; di MySQL kolomnya menolak dengan `1366 Incorrect string value`,
 * dan yang naik ke pekerja bukan lagi kegagalan pengiriman melainkan
 * `QueryException` yang menyebut jalur soket dan nama basis data — lalu
 * KALIMAT ITU yang digambar layar. Maka penyaring ini, yang dilewati SETIAP
 * pesan penyedia, memaksa keluarannya sah lebih dulu.
 */
final class ProviderErrorScrubber
{
    public const LIMIT = 480;

    /**
     * @param  list<string>  $secrets
     */
    public static function scrub(string $text, array $secrets = []): string
    {
        $out = $text;

        foreach ($secrets as $secret) {
            $secret = (string) $secret;
            if ($secret !== '' && strlen($secret) >= 4) {
                $out = str_replace($secret, '[rahasia]', $out);
            }
        }

        $out = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._\-|]+/i', 'Bearer [rahasia]', $out);
        $out = (string) preg_replace('/\b(access_token|app_secret|verify_token|secret|token)=[^&\s"\'>]+/i', '$1=[rahasia]', $out);
        $out = (string) preg_replace('/(https?:\/\/[^\s"\'?]+)\?[^\s"\']*/i', '$1?[…]', $out);
        $out = (string) preg_replace('/\+?\d{8,15}\b/', '[nomor]', $out);

        // SEBELUM dipotong: `Str::limit()` memotong menurut karakter, dan
        // memotong byte yang bukan UTF-8 sah hanya memindahkan masalahnya.
        $out = (string) mb_convert_encoding($out, 'UTF-8', 'UTF-8');

        return Str::limit(trim($out), self::LIMIT, '…');
    }

    /** Untuk pesan yang datang dari WhatsAppChannel: rahasia yang dikenal ikut disamarkan. */
    public static function whatsapp(string $text): string
    {
        return self::scrub($text, WhatsAppSetup::secrets());
    }

    /**
     * Untuk pesan yang datang dari WebPushChannel (P-3e). Yang disamarkan:
     * kunci PRIVAT VAPID, DAN endpoint langganan yang sedang dikirimi.
     *
     * Kunci publik TIDAK disamarkan: ia memang dikirim ke setiap peramban, dan
     * menyamarkannya hanya membuat galat "kunci salah" tidak terbaca.
     *
     * ENDPOINT DISAMARKAN, DAN VERSI PERTAMA KELAS INI MENGATAKAN SEBALIKNYA
     * (putaran verifikasi: A-6/B-4). Kalimat lamanya berbunyi "ia bukan
     * rahasia bersama, melainkan alamat milik satu perangkat" — sementara
     * PushRotationController di paket yang sama menulis, dengan benar, bahwa
     * endpoint push "sudah menjadi kapabilitas dalam standarnya sendiri: siapa
     * pun yang memegangnya bisa mem-POST ke langganan itu", dan `POST
     * push/rotate` memang memakainya sebagai SATU-SATUNYA kredensial. Dua
     * kalimat itu tidak bisa sama-sama benar, dan yang benar adalah yang
     * kedua. Pesan Guzzle memuat URL permintaan lengkap, jadi tanpa penyamaran
     * ini endpoint utuh mendarat di kolom "Galat / alasan" yang dibaca SETIAP
     * pemegang core.update, ikut ke setiap cadangan, dan ikut ke setiap
     * tangkapan layar yang dikirim orang saat minta bantuan. Yang menjawab
     * "perangkat mana yang gagal" adalah LABEL perangkat, yang memang ada di
     * kalimatnya — dan yang justru dipilih untuk kolom `recipient` dengan
     * alasan yang sama persis.
     */
    public static function webPush(string $text, ?string $endpoint = null): string
    {
        $secrets = WebPushSetup::secrets();

        if ($endpoint !== null && trim($endpoint) !== '') {
            $secrets[] = trim($endpoint);
        }

        return self::scrub($text, $secrets);
    }
}
