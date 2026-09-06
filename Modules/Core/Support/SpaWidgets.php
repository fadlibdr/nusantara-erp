<?php

namespace Modules\Core\Support;

/**
 * Katalog widget dasbor SPA, dibaca dari `public/app/js/views/widgets/registry.js`
 * (Fase 1 / P1-D).
 *
 * Saudara SpaNav, dengan alasan yang persis sama. Susunan dasbor disimpan di
 * preferensi `dashboard.layout`, dan validator preferensi harus tahu id widget
 * mana yang SAH. Menyalin 19 id ke PHP berarti daftar yang basi pada sunting
 * pertama — dan yang basi di sini bukan tampilan melainkan VALIDATOR: susunan
 * yang sah ditolak 422, atau id mati diterima selamanya. Berkas registry.js
 * ADALAH aplikasinya (SPA tanpa build, dilayani apa adanya), jadi membacanya
 * tidak menambah artefak yang bisa hilang saat deploy.
 *
 * DEGRADASI, sama seperti SpaNav: berkas tidak terbaca → daftar KOSONG, dan
 * pemanggilnya (UserPreferences) memperlakukan daftar kosong sebagai "tidak
 * bisa memeriksa keanggotaan" lalu jatuh ke pemeriksaan BENTUK saja. Susunan
 * yang ditolak karena berkas tidak terbaca akan terbaca sebagai laci yang
 * rusak, dan itu lebih buruk daripada menyimpan satu id yang SPA sendiri sudah
 * menyaringnya lagi (resolveLayout melewati id yang tidak ada di katalog).
 *
 * Memo per proses: satu PUT susunan tidak boleh membaca berkasnya dua kali.
 */
final class SpaWidgets
{
    /** Ukuran yang dikenal katalog; cermin SIZES di registry.js. */
    public const SIZES = ['kecil', 'sedang', 'lebar'];

    /** @var list<string>|null */
    private static ?array $ids = null;

    /** @var array<string, list<string>>|null */
    private static ?array $defaults = null;

    /**
     * Setiap `id: '<x>'` pada entri katalog — 'inbox', 'ar-aging', …
     *
     * Diikat pada indentasi empat spasi entri katalog, sehingga `id:` yang
     * muncul di dalam kode pembantu di bawahnya (normalise(), resolveLayout())
     * tidak ikut terbaca sebagai widget.
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        if (self::$ids === null) {
            preg_match_all("/^    id: '([a-z0-9-]+)',$/m", self::source(), $matches);
            self::$ids = array_values(array_unique($matches[1]));
        }

        return self::$ids;
    }

    /**
     * Susunan bawaan per peran, `peran => ['id:ukuran', …]`.
     *
     * Dibaca supaya DashboardDefaultsTest bisa membuktikan — terhadap
     * RoleSeeder::intended() yang asli — bahwa setiap peran demo mendapat
     * sedikitnya satu widget. Aplikasi sendiri tidak memakainya: bawaan
     * dipilih SPA, di peramban.
     *
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        if (self::$defaults !== null) {
            return self::$defaults;
        }

        $block = self::block('export const DEFAULTS = {', '};');
        preg_match_all("/^  '?([a-z-]+)'?: \[([^\]]*)\],$/m", $block, $matches, PREG_SET_ORDER);

        $out = [];
        foreach ($matches as $match) {
            preg_match_all("/'([a-z0-9-]+:[a-z]+)'/", $match[2], $entries);
            $out[$match[1]] = $entries[1];
        }

        return self::$defaults = $out;
    }

    /**
     * Izin yang dituntut sebuah widget: satu nama, '*.approve', daftar nama,
     * atau [] bila widget itu terbuka bagi siapa pun yang punya sesi.
     *
     * @return list<string>
     */
    public static function permissionsOf(string $id): array
    {
        $block = self::block("    id: '{$id}',", '  },');
        if ($block === '') {
            return [];
        }

        if (preg_match("/perm: \[([^\]]*)\]/", $block, $match) === 1) {
            preg_match_all("/'([^']+)'/", $match[1], $names);

            return $names[1];
        }

        if (preg_match("/perm: '([^']+)'/", $block, $match) === 1) {
            return [$match[1]];
        }

        return [];
    }

    public static function has(string $id): bool
    {
        $ids = self::ids();

        return $ids === [] || in_array($id, $ids, true);
    }

    /** Dipakai uji yang menulis registry.js tiruan; tidak dipanggil aplikasi. */
    public static function flush(): void
    {
        self::$ids = null;
        self::$defaults = null;
    }

    private static function source(): string
    {
        $path = public_path('app/js/views/widgets/registry.js');

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /** Potongan sumber dari $from sampai $to pertama sesudahnya; '' bila tidak ada. */
    private static function block(string $from, string $to): string
    {
        $source = self::source();
        $start = strpos($source, $from);
        if ($start === false) {
            return '';
        }

        $end = strpos($source, $to, $start + strlen($from));

        return $end === false ? substr($source, $start) : substr($source, $start, $end - $start + strlen($to));
    }
}
