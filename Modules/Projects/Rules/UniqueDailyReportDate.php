<?php

namespace Modules\Projects\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Modules\Projects\Models\DailyReport;

/**
 * Satu laporan per proyek per hari — dibandingkan SEBAGAI TANGGAL, bukan
 * sebagai string.
 *
 * Rule::unique membandingkan string: SQLite menyimpan report_date sebagai
 * "2026-03-25 00:00:00", kiriman formulir berbunyi "2026-03-25", keduanya
 * tidak pernah sama — validasi lolos, lalu indeks unik basis data menolak
 * dengan HTTP 500 UNIQUE constraint failed. whereDate menanyakan pertanyaan
 * yang sama dengan indeksnya, per tanggal, dan berlaku sama di SQLite maupun
 * MySQL. Indeks uniknya tetap ada sebagai jaring terakhir; aturan ini yang
 * membuat jawabannya 422 berbahasa manusia, bukan 500.
 *
 * Alternatif yang DITOLAK, supaya tidak ada yang mencobanya lagi: mengganti
 * cast model menjadi date:Y-m-d membuat baris BARU tersimpan "2026-03-25"
 * sementara baris lama tetap "... 00:00:00" — dan indeks unik, yang
 * membandingkan string mentah, akan MENERIMA duplikat dari tanggal mana pun
 * yang tercatat sebelum perubahan. Menulis ulang baris lama juga bukan
 * pilihan: itu data demo yang hidup.
 *
 * Kelas Rules pertama di basis kode ini — dibenarkan karena dipakai DUA
 * request (store dan update) dan keputusannya butuh prosa sepanjang ini.
 */
class UniqueDailyReportDate implements ValidationRule
{
    public function __construct(
        private readonly ?int $projectId,
        private readonly ?int $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->projectId === null || ! is_string($value) || trim($value) === '') {
            return; // project_id dan format tanggal punya aturannya sendiri.
        }

        try {
            $date = Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return; // aturan 'date' yang menolaknya, bukan aturan ini.
        }

        $exists = DailyReport::query()
            ->where('project_id', $this->projectId)
            ->whereDate('report_date', $date)
            ->when($this->ignoreId !== null, fn ($query) => $query->whereKeyNot($this->ignoreId))
            ->exists();

        if ($exists) {
            $fail('Sudah ada laporan harian untuk proyek ini pada tanggal tersebut.');
        }
    }
}
