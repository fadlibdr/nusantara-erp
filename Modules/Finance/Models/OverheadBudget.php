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
 * config/erp.php sengaja TIDAK memuat entri 'OVB' — kodenya datang dari
 * fallback DocumentNumberService (OVB/2026/IX/0001), preseden yang sama dengan
 * 'BSL' milik ProjectBaseline: menambah entri di sana akan memecahkan
 * DocumentFormatValidationTest yang memaku jumlah jenis dokumen yang dikirim,
 * dan yang hilang hanyalah dua angka nol di depan.
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
        ];
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
