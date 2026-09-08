<?php

namespace Modules\Estimation\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Projects\Models\Project;

/**
 * RAP — Rencana Anggaran Pelaksanaan (internal execution cost budget).
 *
 * REVISI (F-2 / T2.5): kolomnya persis kolom Core\Traits\Revisable —
 * `revision`, `superseded_at`, `superseded_by_id` — tetapi TRAIT-nya sengaja
 * TIDAK dipakai, dengan alasan yang sama yang mengeluarkan ProjectBaseline
 * darinya (lihat docblock trait itu: dokumen yang sudah punya pola sendiri
 * tidak memakainya).
 *
 * Alasannya adalah cacat uang, bukan selera. Pada pola Revisable pendahulu
 * distempel "digantikan" SAAT REVISINYA DIBUAT; untuk sebuah RAP itu berarti
 * proyeknya kehilangan anggaran yang berlaku sejak detik seseorang mulai
 * menyusun revisinya sampai revisi itu disetujui — dan selama jendela itu
 * BudgetGateService tidak menemukan RAP, jadi ia DIAM dan setiap PO lewat tanpa
 * diperiksa. Maka RAP mengikuti BaselineService: penggantian ditulis saat
 * revisinya DISETUJUI, di dalam transaksi yang sama (RapService::approve), dan
 * sampai saat itu revisi 0 tetap mengatur.
 */
class CostBudget extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    public string $documentType = 'RAP';

    protected $table = 'est_cost_budgets';

    protected $casts = [
        'status' => DocumentStatus::class,
        'target_margin_pct' => 'decimal:4',
        'total_budget' => 'decimal:2',
        // F-2 — revisi RAP. Bentuk yang sama dengan prj_baselines: rantai
        // append-only, dan dua kolom yang ditulis pada pendahulu saat penerusnya
        // disetujui.
        'revision' => 'integer',
        'superseded_at' => 'datetime',
    ];

    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class, 'boq_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CostBudgetItem::class, 'cost_budget_id');
    }

    /**
     * The job this budget is executed against — est_cost_budgets.project_id,
     * which RapService keeps in step with the BOQ's own project.
     *
     * Declared so the printed RAP can put the PROYEK box on its letterhead
     * without the print composer reaching into prj_projects by hand. Nullable:
     * a RAP can be costed against a BOQ before the project is opened.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** RAP yang direvisi menjadi RAP ini (null pada revisi 0). */
    public function revisedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revised_from_id');
    }

    /** Revisi yang menggantikan RAP ini (null selama ia masih berlaku). */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * RAP yang MENGATUR proyeknya: disetujui dan belum digantikan.
     *
     * Kembaran ProjectBaseline::isCurrent(), dengan alasan yang sama — sebuah
     * proyek boleh menyimpan banyak RAP disetujui sepanjang hidupnya, tetapi
     * hanya satu yang sedang menghakimi PO berikutnya.
     */
    public function isGoverning(): bool
    {
        if ($this->status !== DocumentStatus::Approved || $this->superseded_at !== null) {
            return false;
        }

        if ($this->project_id === null) {
            return true;
        }

        /*
         * DAN TIDAK ADA SAUDARA BER-ID LEBIH BESAR YANG JUGA BERLAKU
         * (verifikasi F-2 putaran 2). "Disetujui dan belum digantikan" adalah
         * predikat per BARIS, sedangkan RapService::governing() memilih id
         * TERBESAR di antara baris-baris itu — jadi pada data warisan yang
         * memuat dua RAP disetujui, KEDUA barisnya menandai dirinya mengatur
         * dan layar riwayat mencetak Rp 1.000.000.000 sebagai "berlaku"
         * sementara gerbang PO/SPK menolak dengan Rp 700.000.000 milik yang
         * lain. Satu kueri exists() pada indeks est_cost_budgets_governing_index,
         * hanya untuk baris yang sudah lolos predikat murah di atas.
         */
        return ! static::query()
            ->where('project_id', $this->project_id)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('superseded_at')
            ->where('id', '>', $this->id)
            ->exists();
    }
}
