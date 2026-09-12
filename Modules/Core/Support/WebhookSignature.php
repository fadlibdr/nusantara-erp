<?php

namespace Modules\Core\Support;

/**
 * `X-Nusantara-Signature` — KONTRAK YANG DIBACA ORANG LAIN (P-3d, perangkap D).
 *
 * Resepnya lengkap di sini karena penerimanya harus bisa menirunya tanpa
 * membaca satu baris pun kode kita. Kalimat yang sama ada di
 * `docs/PANDUAN-ADMINISTRATOR.md` §5.14 dan di `docs/api/openapi.json`, dan
 * `WebhookSignatureTest` memaku ketiganya sama.
 *
 * BENTUK HEADER
 *
 *     X-Nusantara-Signature: t=1757683200,v1=3f9c…  (hex, 64 karakter)
 *
 * APA YANG DITANDATANGANI
 *
 *     hash_hmac('sha256', "{t}.{badan mentah}", rahasia_langganan)
 *
 * `t` adalah detik Unix dan IA IKUT DITANDATANGANI — itulah yang membuat
 * jendela waktu berarti. Sebuah tanda tangan yang hanya menutupi badan
 * membiarkan penyerang memutar ulang kiriman lama dengan stempel baru.
 *
 * "BADAN MENTAH" ADALAH BYTE YANG BENAR-BENAR DIKIRIM. Bukan array yang
 * di-`json_encode` lagi di tempat lain: dua encoder yang berbeda urutan kunci,
 * escape garis miring, atau presisi angka menghasilkan dua byte yang berbeda,
 * dan penerima yang menghitung ulang tanda tangan atas badan yang diterimanya
 * akan mendapat nilai yang tidak cocok — tanpa ada yang salah di kedua sisi.
 * Maka `WebhookPayload::encode()` menghasilkan STRING sekali, string itu yang
 * ditandatangani, string itu yang dikirim, dan string itu yang disimpan di
 * kolom `payload` baris log.
 *
 * CARA PENERIMA MEMERIKSANYA
 *
 *   1. baca header, pisahkan `t` dan `v1`;
 *   2. tolak bila `|sekarang − t| > 300 detik` (TOLERANCE);
 *   3. hitung `hash_hmac('sha256', t.'.'.badan_mentah, rahasia)`;
 *   4. bandingkan dengan `hash_equals()` — perbandingan `===` atas string
 *      membocorkan posisi karakter pertama yang berbeda lewat waktu eksekusi;
 *   5. tolak kiriman dengan `X-Nusantara-Event` yang sudah pernah diproses
 *      (id peristiwa stabil di seluruh lima percobaan — percobaan ulang
 *      MEMBAWA ID YANG SAMA, karena percobaan ulang bukan peristiwa baru).
 */
final class WebhookSignature
{
    public const HEADER = 'X-Nusantara-Signature';

    public const EVENT_HEADER = 'X-Nusantara-Event';

    public const DELIVERY_HEADER = 'X-Nusantara-Delivery';

    public const ALGORITHM = 'sha256';

    /** Jendela yang DISARANKAN kepada penerima, dalam detik. */
    public const TOLERANCE = 300;

    /** Nilai header untuk badan ini, pada detik ini. */
    public static function header(string $rawBody, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.self::digest($rawBody, $secret, $timestamp);
    }

    public static function digest(string $rawBody, string $secret, int $timestamp): string
    {
        return hash_hmac(self::ALGORITHM, $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * Pemeriksaan dari sisi PENERIMA, ditulis di sini supaya uji bisa memakai
     * jalur yang sama dengan yang dijanjikan dokumen kepada orang lain.
     */
    public static function verify(string $header, string $rawBody, string $secret, ?int $now = null): bool
    {
        $now ??= time();
        $parts = [];

        foreach (explode(',', $header) as $piece) {
            $pair = explode('=', trim($piece), 2);

            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];

        if (abs($now - $timestamp) > self::TOLERANCE) {
            return false;
        }

        return hash_equals(self::digest($rawBody, $secret, $timestamp), $parts['v1']);
    }

    /** Rahasia langganan baru: 64 karakter hex, 256 bit. */
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
