<?php

namespace Modules\Core\Support;

/**
 * NPWP 15 digit / NPWP 16 digit (NIK) / NITKU — satu kelas nilai (P-3b, T3b.1).
 *
 * DI CORE, BUKAN DI FINANCE, karena kolom npwp hidup di EMPAT tabel milik
 * empat modul: core_company (Core), crm_customers (Crm), prc_vendors
 * (Procurement), hr_employees (HrPayroll) — dan Modules/Core tidak boleh
 * meng-import modul fitur, sementara modul fitur boleh meng-import Core
 * (pola PhoneNumber, P-3a). Satu kelas, satu Rule, empat pintu tulis.
 *
 * KLASIFIKASI HANYA DARI PANJANG DIGIT — dan itu keputusan, bukan
 * kekurangan. PMK 112/PMK.03/2022: NPWP orang pribadi penduduk = NIK (16
 * digit); NPWP badan/instansi = 16 digit; NITKU = NPWP 16 digit + 6 digit
 * kode unit/cabang = 22 digit; format lama 15 digit masih beredar dan masih
 * tercetak di dokumen lama. DJP tidak menerbitkan digit periksa publik untuk
 * NIK maupun NPWP-16, jadi TIDAK ADA algoritma yang memutuskan "16 digit ini
 * bukan NIK yang benar" — mengarangnya berarti menolak pegawai sungguhan
 * dengan alasan yang tidak bisa dijelaskan kepada siapa pun. Yang bisa
 * dibaca dari 16 digit hanyalah bahwa ia 16 digit; label jenisnya
 * mengatakan "NPWP 16 digit / NIK", bukan salah satunya.
 *
 * MAJU-SAJA. Kelas ini tidak pernah menolak saat MEMBACA: format() memulangkan
 * nilai lama yang tidak dikenali apa adanya, dan pembaca lama (TaxExportService::
 * digits, cetakan, resource) tidak berubah. Aturannya hanya berdiri di pintu
 * tulis lewat Modules\Core\Rules\ValidNpwp.
 *
 * Yang DISIMPAN adalah yang diketik (dipangkas), bukan bentuk kanonik: satu
 * transformasi tulis adalah satu tempat lagi aturan bisa bocor, dan pembaca
 * yang membutuhkan digit sudah menormalkan sendiri (TaxExportService::digits).
 */
final class Npwp
{
    public const KIND_NPWP15 = 'npwp15';

    public const KIND_NPWP16 = 'npwp16';

    public const KIND_NITKU = 'nitku';

    /** Panjang digit per jenis — LITERAL, dan dipaku literal di ujinya. */
    public const LENGTH_NPWP15 = 15;

    public const LENGTH_NPWP16 = 16;

    public const LENGTH_NITKU = 22;

    /** Pemisah yang boleh ditulis orang dan dibuang saat dibaca: titik, strip, spasi. Huruf TIDAK dibuang. */
    private const SEPARATORS = '/[.\-\s]/';

    /** Buang titik/strip/spasi; nilai kosong menjadi ''. Tidak pernah null, tidak pernah menolak. */
    public static function normalize(?string $value): string
    {
        return (string) preg_replace(self::SEPARATORS, '', trim((string) $value));
    }

    /** Jenis dari PANJANG DIGIT saja: npwp15 | npwp16 | nitku | null (tidak sah / kosong). */
    public static function kind(?string $value): ?string
    {
        $digits = self::normalize($value);

        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            return null;
        }

        return match (strlen($digits)) {
            self::LENGTH_NPWP15 => self::KIND_NPWP15,
            self::LENGTH_NPWP16 => self::KIND_NPWP16,
            self::LENGTH_NITKU => self::KIND_NITKU,
            default => null,
        };
    }

    public static function isValid(?string $value): bool
    {
        return self::kind($value) !== null;
    }

    public static function label(?string $kind): ?string
    {
        return match ($kind) {
            self::KIND_NPWP15 => 'NPWP 15 digit (format lama)',
            self::KIND_NPWP16 => 'NPWP 16 digit / NIK',
            self::KIND_NITKU => 'NITKU (22 digit)',
            default => null,
        };
    }

    /**
     * Bentuk tampilan per jenis. 15 digit memakai format cetak lama
     * XX.XXX.XXX.X-XXX.XXX yang tercetak di setiap dokumen sebelum 2024;
     * 16 dan 22 digit ditampilkan sebagai digit utuh — Coretax menampilkan
     * keduanya tanpa pemisah, dan mengarang pengelompokan bukan tugas paket
     * ini. Nilai yang tidak dikenali dipulangkan APA ADANYA (maju-saja);
     * kosong → null.
     */
    public static function format(?string $value): ?string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        $digits = self::normalize($raw);

        return match (self::kind($raw)) {
            self::KIND_NPWP15 => sprintf(
                '%s.%s.%s.%s-%s.%s',
                substr($digits, 0, 2),
                substr($digits, 2, 3),
                substr($digits, 5, 3),
                substr($digits, 8, 1),
                substr($digits, 9, 3),
                substr($digits, 12, 3),
            ),
            self::KIND_NPWP16, self::KIND_NITKU => $digits,
            default => $raw,
        };
    }

    /**
     * Semua yang ingin diketahui sebuah layar tentang satu nilai — untuk
     * resource/rekap yang benar-benar menampilkannya.
     *
     * @return array{raw: ?string, digits: string, kind: ?string, kind_label: ?string, display: ?string, valid: bool}
     */
    public static function describe(?string $value): array
    {
        $kind = self::kind($value);

        return [
            'raw' => $value === null || trim($value) === '' ? null : $value,
            'digits' => self::normalize($value),
            'kind' => $kind,
            'kind_label' => self::label($kind),
            'display' => self::format($value),
            'valid' => $kind !== null,
        ];
    }
}
