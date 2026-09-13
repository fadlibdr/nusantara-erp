<?php

namespace Modules\Core\Support;

/**
 * "Chrome di Android" dari sebuah User-Agent (P-3e, T3e.2).
 *
 * Dipakai SEKALI, saat sebuah perangkat mendaftar, dan yang disimpan hanya
 * hasilnya. Alasannya bukan kerapian: User-Agent lengkap adalah sidik jari
 * peramban (versi patch, model perangkat, build), tidak satu baris kode pun di
 * aplikasi ini membutuhkannya, dan kolom yang menyimpannya akan ikut ke setiap
 * salinan cadangan selamanya. Yang dibutuhkan layar hanyalah cukup untuk
 * menjawab satu pertanyaan: "yang mana yang saya cabut?"
 *
 * Deteksinya sengaja KASAR dan urutannya penting — setiap peramban di Android
 * menulis "Chrome" di User-Agent-nya, setiap peramban di iOS menulis "Safari",
 * dan Edge menulis "Chrome" juga. Yang lebih spesifik diperiksa lebih dulu.
 * Yang tidak dikenali tidak ditebak: ia menjadi "Peramban lain", dan orangnya
 * tetap bisa membedakan perangkat dari tanggal pendaftarannya.
 */
final class PushDeviceLabel
{
    /** Potongan User-Agent → nama peramban, YANG LEBIH SPESIFIK LEBIH DULU. */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser' => 'Samsung Internet',
        'Firefox' => 'Firefox',
        'CriOS' => 'Chrome',
        'FxiOS' => 'Firefox',
        'Chrome' => 'Chrome',
        'Safari' => 'Safari',
    ];

    /** Potongan User-Agent → nama sistem, YANG LEBIH SPESIFIK LEBIH DULU. */
    private const PLATFORMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'Macintosh' => 'Mac',
        'Mac OS X' => 'Mac',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    public static function fromUserAgent(?string $userAgent): string
    {
        $agent = trim((string) $userAgent);

        if ($agent === '') {
            return 'Perangkat tanpa label';
        }

        $browser = self::firstMatch($agent, self::BROWSERS) ?? 'Peramban lain';
        $platform = self::firstMatch($agent, self::PLATFORMS);

        $label = $platform === null ? $browser : "{$browser} di {$platform}";

        // Kolomnya 80 karakter; potong di sini supaya yang menabraknya adalah
        // label, bukan penyimpanan.
        return mb_substr($label, 0, 80);
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function firstMatch(string $agent, array $map): ?string
    {
        foreach ($map as $needle => $name) {
            if (str_contains($agent, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
