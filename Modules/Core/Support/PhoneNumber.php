<?php

namespace Modules\Core\Support;

/**
 * Nomor telepon E.164 (P-3a, T3a.3): satu bentuk simpan, satu validator.
 *
 * Yang DITERIMA lalu dinormalkan: spasi, strip, titik, dan kurung dibuang;
 * "0812…" (lokal Indonesia) menjadi "+62812…"; "62812…" tanpa tanda plus
 * menjadi "+62812…"; "0062…" menjadi "+62…". Yang DISIMPAN selalu
 * `^\+[1-9]\d{7,14}$` — tanda plus, 8–15 digit, tanpa apa pun yang lain —
 * karena kanal WhatsApp dan webhook Meta mencocokkan nomor sebagai string,
 * dan "+62 812" ≠ "+62812" bagi mereka.
 *
 * Yang DITOLAK: digit telanjang tanpa 0/62/+ (tidak bisa ditebak negaranya),
 * huruf, terlalu pendek/panjang, kode negara 0.
 */
final class PhoneNumber
{
    public const PATTERN = '/^\+[1-9]\d{7,14}$/';

    public const MESSAGE = 'Nomor WhatsApp harus format internasional E.164, mis. +6281234567890 '
        .'(8–15 digit setelah +, tanpa spasi/strip). Nomor lokal 08… diterima dan diubah ke +62.';

    /** Bentuk simpan, atau null bila tidak bisa dinormalkan menjadi E.164 yang sah. */
    public static function normalize(?string $raw): ?string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        $value = (string) preg_replace('/[\s\-.()]/', '', $value);

        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        } elseif (str_starts_with($value, '0')) {
            $value = '+62'.substr($value, 1);
        } elseif (str_starts_with($value, '62')) {
            $value = '+'.$value;
        }

        return preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }

    public static function isValid(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }

    /** Bentuk yang dikirim ke Meta: digit saja, tanpa '+'. */
    public static function digits(string $e164): string
    {
        return ltrim($e164, '+');
    }
}
