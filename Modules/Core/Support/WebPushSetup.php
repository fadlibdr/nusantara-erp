<?php

namespace Modules\Core\Support;

/**
 * Apa yang sudah/belum diisi pemilik untuk kanal web push (P-3e, T3e.1) —
 * dibaca dari config('erp.push'), yang seluruhnya datang dari .env dan KOSONG
 * di repo. Saudara WhatsAppSetup, dengan satu perbedaan yang penting.
 *
 * PERBEDAAN ITU: kanal ini punya kunci PUBLIK yang memang harus keluar ke
 * peramban (applicationServerKey pada PushManager.subscribe) dan kunci PRIVAT
 * yang tidak boleh keluar ke mana pun. Karena itu tidak ada satu pun getter
 * publik untuk kunci privat di kelas ini: `auth()` menyerahkan seluruh berkas
 * VAPID langsung kepada pustaka pengirim, dan `secrets()` hanya dipakai
 * ProviderErrorScrubber untuk MENYAMARKANNYA dari teks galat yang akan
 * disimpan. Seorang pemanggil yang ingin mencetak kunci privat ke layar harus
 * menulis barisnya sendiri — dan itu terlihat di diff.
 *
 * Satu tempat yang menjawab "kanal ini bisa mengirim?", dipakai DeliveryGate
 * (skipped dengan sebab), WebPushChannel (sebelum menyentuh jaringan), dan
 * layar Profil (tombol Aktifkan yang tidak boleh ditawarkan pada instalasi
 * yang VAPID-nya kosong).
 */
final class WebPushSetup
{
    public const SKIP_UNCONFIGURED = 'Web push belum dikonfigurasi (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY / VAPID_SUBJECT di .env kosong — '
        .'jalankan `php artisan core:vapid-keys` lalu isi ketiganya, DEPLOYMENT.md §11.3).';

    /** Kunci publik base64url — MEMANG publik: peramban memerlukannya untuk berlangganan. */
    public static function publicKey(): ?string
    {
        return self::env('vapid_public_key');
    }

    /**
     * mailto:/https: pemilik, dikirim di header VAPID supaya layanan push tahu
     * siapa yang dihubungi bila pengiriman kami bermasalah (RFC 8292 §2.1).
     */
    public static function subject(): ?string
    {
        return self::env('vapid_subject');
    }

    public static function ttlSeconds(): int
    {
        return max(60, (int) config('erp.push.ttl_seconds', 86400));
    }

    public static function timeoutSeconds(): int
    {
        return max(3, (int) config('erp.push.timeout_seconds', 15));
    }

    /** Ketiganya terisi? Tanpa salah satunya tidak ada satu permintaan pun yang boleh keluar. */
    public static function configured(): bool
    {
        return self::publicKey() !== null && self::privateKey() !== null && self::subject() !== null;
    }

    /**
     * Sebab `skipped` karena KONFIGURASI (bukan karena orangnya), atau null
     * bila kanal boleh mencoba. Diperiksa SEBELUM pengirim dibuat — tanpa
     * VAPID tidak ada satu permintaan pun yang keluar dari mesin.
     */
    public static function skipReason(): ?string
    {
        if (! self::configured()) {
            return self::SKIP_UNCONFIGURED;
        }

        $subject = (string) self::subject();

        // Layanan push menolak header VAPID yang subject-nya bukan mailto:
        // atau https: (RFC 8292). Menolaknya di sini berarti "belum
        // dikonfigurasi dengan benar" terbaca sebelum berangkat, bukan sebagai
        // 403 dari layanan push yang harus ditebak artinya.
        if (! str_starts_with($subject, 'mailto:') && ! str_starts_with($subject, 'https://')) {
            return 'VAPID_SUBJECT harus berupa mailto:… atau https://… (RFC 8292); layanan push menolak header VAPID dengan subject lain.';
        }

        return null;
    }

    /**
     * Berkas VAPID untuk pustaka pengirim. SATU-SATUNYA jalan keluar kunci
     * privat dari kelas ini, dan tujuannya bukan layar melainkan
     * Minishlink\WebPush\WebPush yang menandatangani header.
     *
     * Bentuk base64url apa adanya: kelas WebPush menormalkannya lewat
     * VAPID::validate. (VAPID::getVapidHeaders() menuntut kunci MENTAH dan
     * melempar "only uncompressed keys are supported" untuk bentuk ini —
     * diukur 13 Sep 2026; karena itu pengirimnya adalah kelas WebPush, bukan
     * getVapidHeaders.)
     *
     * @return array{VAPID: array{subject: string, publicKey: string, privateKey: string}}
     */
    public static function auth(): array
    {
        return ['VAPID' => [
            'subject' => (string) self::subject(),
            'publicKey' => (string) self::publicKey(),
            'privateKey' => (string) self::privateKey(),
        ]];
    }

    /**
     * Nilai-nilai yang tidak boleh muncul di teks mana pun yang disimpan.
     * Kunci PUBLIK tidak termasuk: ia dikirim ke setiap peramban.
     *
     * @return list<string>
     */
    public static function secrets(): array
    {
        return array_values(array_filter([self::privateKey()]));
    }

    /** PRIVAT, dan tetap begitu — lihat docblock kelas. */
    private static function privateKey(): ?string
    {
        return self::env('vapid_private_key');
    }

    private static function env(string $key): ?string
    {
        $value = trim((string) config("erp.push.{$key}", ''));

        return $value === '' ? null : $value;
    }
}
