<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;

/**
 * OVB — anggaran overhead perusahaan untuk satu tahun buku (F-2 / T2.4).
 *
 * Saudara RAP, satu tingkat di atasnya: RAP menganggarkan biaya SEBUAH PROYEK,
 * OVB menganggarkan biaya yang tidak dimiliki proyek mana pun — kantor, gaji
 * staf pusat, sewa, penyusutan. Keduanya Approvable dengan maker-checker yang
 * sama, dan keduanya dibandingkan dengan realisasi yang SUDAH ada, bukan dengan
 * buku baru: RAP dengan fin_project_costs, OVB dengan mutasi akun COA-nya
 * sendiri di buku besar.
 *
 * KODENYA MEMBAWA TAHUN BUKUNYA, BUKAN TAHUN JAM SERVER (verifikasi F-2).
 * Semula OVB sengaja dibiarkan tanpa entri config('erp.documents') dan memakai
 * fallback DocumentNumberService — dan fallback itu merender {Y} sebagai tahun
 * BERJALAN. Terukur: OVB untuk tahun buku 2031 yang dibuat hari ini berkode
 * 'OVB/2026/IX/0001', dan kalimat penolakan "satu per tahun" berbunyi "Tahun
 * buku 2031 sudah punya anggaran overhead yang disetujui (OVB/2026/IX/0001)" —
 * dua tahun berbeda dalam satu kalimat, pada dokumen yang seluruh identitasnya
 * adalah sebuah tahun. Sekarang formatnya terdaftar ('OVB/{Y}/{N4}', tanpa
 * bulan romawi: bulan pembuatan tidak berarti apa-apa di sini) dan
 * documentNumberYear() memasok tahun bukunya, jadi urutannya pun terpisah per
 * tahun buku.
 */
class OverheadBudget extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    public string $documentType = 'OVB';

    protected $table = 'fin_overhead_budgets';

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'period_year' => 'integer',
            'total_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Tahun yang dipakai menomori dokumen ini: TAHUN BUKUNYA.
     *
     * Dibaca HasDocumentNumber saat mencetak kode, jadi ia harus menjawab
     * sebelum baris tersimpan — period_year sudah terisi di titik itu (create()
     * mengisinya bersama status). Kalau belum, null memulangkan perilaku
     * bawaan: tahun berjalan, bukan kode tanpa tahun.
     */
    public function documentNumberYear(): ?int
    {
        $year = (int) ($this->period_year ?? 0);

        return $year > 0 ? $year : null;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OverheadBudgetLine::class, 'overhead_budget_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
