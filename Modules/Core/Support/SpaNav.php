<?php

namespace Modules\Core\Support;

/**
 * Rute dan prefix sidebar SPA, dibaca dari `public/app/js/schema.js` (P1-C).
 *
 * KENAPA MEMBACA BERKAS DAN BUKAN MENYALIN DAFTARNYA. NAV adalah 14 grup dan
 * ~131 baris yang berubah setiap paket; sebuah salinan PHP-nya akan basi pada
 * sunting pertama, dan yang basi di sini bukan tampilan melainkan VALIDATOR —
 * favorit yang sah ditolak 422, atau rute mati diterima selamanya. Berkas itu
 * ADALAH aplikasinya (SPA tanpa build; schema.js dilayani apa adanya), jadi
 * membacanya tidak menambah artefak yang bisa hilang saat deploy.
 *
 * DEGRADASI. Berkas tidak terbaca (deploy yang menaruh public/ di tempat lain,
 * izin baca) → daftar KOSONG, dan pemanggilnya (UserPreferences) memperlakukan
 * daftar kosong sebagai "tidak bisa memeriksa keanggotaan" lalu jatuh ke
 * pemeriksaan BENTUK saja. Sebuah favorit yang ditolak karena berkas tidak
 * terbaca akan terbaca sebagai bintang yang rusak, dan itu lebih buruk daripada
 * menyimpan satu rute yang SPA sendiri sudah menyaringnya lagi (app.js
 * shortcutGroups() mencocokkan favorit ke NAV yang sedang terlihat).
 *
 * Memo per proses: satu PUT favorit tidak boleh membaca 200 KB dua kali, dan
 * daftar ini tidak berubah selama proses hidup (berkasnya statis).
 */
final class SpaNav
{
    /** @var list<string>|null */
    private static ?array $routes = null;

    /** @var list<string>|null */
    private static ?array $prefixes = null;

    /**
     * Setiap `route: '<x>'` di dalam blok NAV — 'dashboard', 'r/projects', …
     *
     * @return list<string>
     */
    public static function routes(): array
    {
        if (self::$routes === null) {
            preg_match_all("/route: '([^']+)'/", self::navBlock(), $matches);
            self::$routes = array_values(array_unique($matches[1]));
        }

        return self::$routes;
    }

    /**
     * Prefix grup NAV ('ringkasan', 'crm', … ) — kunci MODULES dan `#/m/<x>`.
     *
     * @return list<string>
     */
    public static function prefixes(): array
    {
        if (self::$prefixes === null) {
            preg_match_all("/^    label: '[^']+', perm: [^,]+, prefix: '([a-z]+)',$/m", self::navBlock(), $matches);
            self::$prefixes = array_values(array_unique($matches[1]));
        }

        return self::$prefixes;
    }

    public static function hasRoute(string $route): bool
    {
        $routes = self::routes();

        return $routes === [] || in_array($route, $routes, true);
    }

    public static function hasPrefix(string $prefix): bool
    {
        $prefixes = self::prefixes();

        return $prefixes === [] || in_array($prefix, $prefixes, true);
    }

    /** Dipakai uji yang menulis schema.js tiruan; tidak dipanggil aplikasi. */
    public static function flush(): void
    {
        self::$routes = null;
        self::$prefixes = null;
    }

    private static function navBlock(): string
    {
        $path = public_path('app/js/schema.js');

        if (! is_readable($path)) {
            return '';
        }

        $source = (string) file_get_contents($path);
        $start = strpos($source, 'export const NAV = [');

        return $start === false ? '' : substr($source, $start);
    }
}
