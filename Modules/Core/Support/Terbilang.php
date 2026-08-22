<?php

namespace Modules\Core\Support;

/**
 * Indonesian number-to-words, for kwitansi / invoice "terbilang" lines.
 * Terbilang::rupiah(48500000000) => "Empat puluh delapan miliar lima ratus juta rupiah"
 */
class Terbilang
{
    private const WORDS = [
        '', 'satu', 'dua', 'tiga', 'empat', 'lima',
        'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas',
    ];

    public static function rupiah(int|float|string $amount): string
    {
        $words = self::spell((int) round((float) $amount));

        return ucfirst(trim(($words === '' ? 'nol' : $words).' rupiah'));
    }

    public static function make(int $number): string
    {
        return $number === 0 ? 'nol' : trim(self::spell($number));
    }

    private static function spell(int $n): string
    {
        return match (true) {
            $n < 0 => trim('minus '.self::spell(abs($n))),
            $n < 12 => self::WORDS[$n],
            $n < 20 => self::spell($n - 10).' belas',
            $n < 100 => trim(self::spell(intdiv($n, 10)).' puluh '.self::spell($n % 10)),
            $n < 200 => trim('seratus '.self::spell($n - 100)),
            $n < 1000 => trim(self::spell(intdiv($n, 100)).' ratus '.self::spell($n % 100)),
            $n < 2000 => trim('seribu '.self::spell($n - 1000)),
            $n < 1000000 => trim(self::spell(intdiv($n, 1000)).' ribu '.self::spell($n % 1000)),
            $n < 1000000000 => trim(self::spell(intdiv($n, 1000000)).' juta '.self::spell($n % 1000000)),
            $n < 1000000000000 => trim(self::spell(intdiv($n, 1000000000)).' miliar '.self::spell($n % 1000000000)),
            default => trim(self::spell(intdiv($n, 1000000000000)).' triliun '.self::spell($n % 1000000000000)),
        };
    }
}
