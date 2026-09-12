<?php

namespace Modules\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Core\Support\Npwp;

/**
 * SATU aturan NPWP untuk SETIAP pintu tulis yang menerima NPWP (P-3b, T3b.1):
 * CustomerStore/Update (Crm), VendorStore/Update (Procurement),
 * EmployeeStore/Update (HrPayroll), PUT core/company (Core), dan kolom npwp
 * impor master data (Core\Support\ImportableResources). Cacat berulang
 * kampanye ini adalah aturan yang benar di satu pintu dan bocor di pintu
 * lain — NpwpTest memaku daftar pintu impor secara literal, dan setiap modul
 * memaku pintunya sendiri.
 *
 * Yang diterima: kosong (nullable diputuskan pintunya), 15 / 16 / 22 digit
 * dengan titik, strip, atau spasi di mana pun. Yang ditolak: panjang lain,
 * huruf. Tidak ada digit periksa — lihat Npwp.
 *
 * MAJU-SAJA lewat unlessUnchanged(): formulir SPA mengirim seluruh baris, jadi
 * sunting nomor telepon pada vendor lama ber-NPWP "N/A" mengirim "N/A" itu
 * kembali. Nilai yang PERSIS sama dengan yang tersimpan bukan penulisan
 * NPWP baru dan lolos; nilai yang berubah diperiksa. Data lama tidak
 * di-backfill, tidak ditolak saat dibaca, dan tidak menyandera kolom lain.
 */
final class ValidNpwp implements ValidationRule
{
    public const MESSAGE = 'NPWP harus 15 digit (format lama), 16 digit (NPWP baru; untuk orang pribadi = NIK), '
        .'atau 22 digit (NITKU = NPWP 16 digit + 6 digit kode cabang). Titik, strip, dan spasi boleh ditulis.';

    public function __construct(private readonly ?string $unchanged = null) {}

    /** Aturan untuk pintu UPDATE: nilai yang sama dengan yang tersimpan tidak diperiksa ulang. */
    public static function unlessUnchanged(?string $stored): self
    {
        return new self($stored === null || trim($stored) === '' ? null : trim($stored));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return; // kosong: 'nullable'/'required' pintunya yang memutuskan
        }

        if ($this->unchanged !== null && trim($value) === $this->unchanged) {
            return; // dikirim kembali apa adanya — bukan penulisan baru
        }

        if (! Npwp::isValid($value)) {
            $fail(self::MESSAGE);
        }
    }
}
