<?php

namespace Modules\Core\Support;

/**
 * Label enum SPA, dibaca dari `public/app/js/enums.js` (Fase 1 / P1-F).
 *
 * Saudara SpaNav / SpaWidgets, dan dipakai HANYA oleh ekspor XLSX. Alasannya
 * sempit dan perlu ditulis: di layar dan di CSV, label enum ditulis peramban
 * lewat `enumLabel()` — fungsi yang SAMA dengan yang dipakai layar daftarnya,
 * jadi pratinjau tidak bisa berbeda dari layar dan CSV tidak bisa berbeda dari
 * pratinjau. Berkas XLSX disusun di server, di mana fungsi itu tidak ada.
 *
 * KENAPA BOLEH DIBACA SAAT JALAN, sementara whitelist kolom tidak. Sebuah
 * whitelist yang basi menolak laporan yang sah (422 yang terbaca sebagai fitur
 * rusak); sebuah label yang basi hanya lebih ringkas — nilai mentahnya yang
 * ditulis, dan itu tidak pernah SALAH, hanya kurang ramah. Maka degradasinya:
 * `public/` tidak terbaca → peta kosong → nilai mentah, tanpa satu galat pun.
 *
 * Tidak ada enum PHP yang diimpor: Core tidak boleh bergantung pada modul
 * fitur, dan `Modules\Finance\Enums\CostCategory` di sini membalik arah itu.
 */
final class SpaEnums
{
    /** @var array<string, array<string, string>>|null */
    private static ?array $enums = null;

    /**
     * nilai → label untuk satu enum SPA; peta kosong bila tidak dikenal.
     *
     * @return array<string, string>
     */
    public static function labels(string $enum): array
    {
        return self::all()[$enum] ?? [];
    }

    /** Label sebuah nilai, atau nilainya sendiri bila tidak dikenal. */
    public static function label(string $enum, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return self::labels($enum)[(string) $value] ?? (string) $value;
    }

    /** @return array<string, array<string, string>> */
    public static function all(): array
    {
        if (self::$enums !== null) {
            return self::$enums;
        }

        $path = public_path('app/js/enums.js');

        if (! is_readable($path)) {
            return self::$enums = [];
        }

        $source = (string) file_get_contents($path);
        $out = [];

        /*
         * Bentuk yang dibaca: `namaEnum: opts([ ['nilai', 'Label'], … ])`.
         * Blok `opts([` … `])` diambil utuh lalu pasangannya dipindai — satu
         * entri boleh tersebar di beberapa baris, dan memang begitu adanya.
         */
        if (preg_match_all('/^  ([a-zA-Z][a-zA-Z0-9]*): opts\(\[(.*?)\]\),$/ms', $source, $matches, PREG_SET_ORDER) === 0) {
            return self::$enums = [];
        }

        foreach ($matches as $match) {
            preg_match_all("/\['([^']*)',\s*'([^']*)'/", $match[2], $pairs, PREG_SET_ORDER);

            $labels = [];
            foreach ($pairs as $pair) {
                $labels[$pair[1]] = $pair[2];
            }

            if ($labels !== []) {
                $out[$match[1]] = $labels;
            }
        }

        return self::$enums = $out;
    }

    /** Dipakai uji yang menulis enums.js tiruan; tidak dipanggil aplikasi. */
    public static function flush(): void
    {
        self::$enums = null;
    }
}
