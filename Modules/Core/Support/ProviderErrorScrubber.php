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
 *   6. dipotong 480 karakter
 *
 * Kode galat Meta (mis. 131026) ≤ 6 digit dan tidak tersentuh aturan 5;
 * wamid bukan angka. Yang hilang dari pesan hanya yang tidak boleh ada.
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

        return Str::limit(trim($out), self::LIMIT, '…');
    }

    /** Untuk pesan yang datang dari WhatsAppChannel: rahasia yang dikenal ikut disamarkan. */
    public static function whatsapp(string $text): string
    {
        return self::scrub($text, WhatsAppSetup::secrets());
    }
}
