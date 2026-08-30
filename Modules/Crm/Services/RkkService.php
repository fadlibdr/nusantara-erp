<?php

namespace Modules\Crm\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Models\RkkDocument;
use Modules\Crm\Models\TenderPackage;
use Modules\Estimation\Models\BoqItem;

/**
 * P7 — the RKK penawaran: its IBPRP rows and its SMKK cost lines.
 *
 * TWO CROSS-MODULE READS, TWO DIFFERENT DEVICES, ON PURPOSE.
 *
 *   prj_risk_register  READ RAW behind Schema::hasTable. ARCHITECTURE.md draws
 *                      no arrow from Crm to Projects — the sales side reaches
 *                      the delivery side through Estimation — so pulling in a
 *                      Projects model here would buy a new dependency arrow to
 *                      answer one question. This is the device
 *                      BastPrerequisiteService uses on qc_ncr and
 *                      TerminBillingService already uses on prj_milestones from
 *                      inside this very module; 'deleted_at IS NULL' is spelled
 *                      by value because a raw read cannot borrow SoftDeletes.
 *
 *   est_boq_items      READ THROUGH ITS MODEL. Crm → Estimation IS a drawn
 *                      arrow (quotation → BOQ), so there is nothing to work
 *                      around, and the model brings the decimal cast the money
 *                      needs.
 *
 * THE SMKK TOTAL IS DERIVED, ALWAYS. Nothing in crm_rkk_smkk_costs stores a
 * rupiah figure; smkkTotal() sums the linked BoQ rows as they stand. A line
 * whose BoQ row has since gone reports amount NULL and is EXCLUDED from the
 * total rather than counted as zero — and the sheet rules that cell. A ruled
 * cell says "this is missing"; a printed 0,00 says "this costs nothing".
 */
class RkkService
{
    public function create(array $data, ?User $by = null): RkkDocument
    {
        TenderPackage::query()->findOrFail((int) $data['tender_package_id']);

        $rkk = new RkkDocument(Arr::except($data, ['code', 'created_by', 'ibprp_links', 'smkk_costs']));
        $rkk->created_by = $by?->id;
        $rkk->save(); // HasDocumentNumber fills the RKK code

        return $rkk;
    }

    public function update(RkkDocument $rkk, array $data): RkkDocument
    {
        $rkk->fill(Arr::except($data, ['code', 'tender_package_id', 'created_by', 'ibprp_links', 'smkk_costs']))->save();

        return $rkk;
    }

    /**
     * Ganti seluruh tautan IBPRP, utuh. Setiap id harus ADA di register hidup
     * — dan, bila RKK menyebut proyeknya, harus milik proyek itu.
     *
     * @param  array<int, int|string>  $riskEntryIds
     */
    public function syncIbprpLinks(RkkDocument $rkk, array $riskEntryIds): RkkDocument
    {
        $ids = array_values(array_unique(array_map('intval', $riskEntryIds)));

        if ($ids !== []) {
            $live = $this->liveRiskEntryIds($ids, $rkk->project_id === null ? null : (int) $rkk->project_id);
            $dangling = array_values(array_diff($ids, $live));

            if ($dangling !== []) {
                throw ValidationException::withMessages([
                    'ibprp_links' => [
                        'Baris IBPRP tidak ditemukan pada register risiko'
                            .($rkk->project_id === null ? '' : ' proyek ini')
                            .': '.implode(', ', $dangling).'.',
                    ],
                ]);
            }
        }

        DB::transaction(function () use ($rkk, $ids): void {
            $rkk->ibprpLinks()->delete();

            foreach ($ids as $order => $id) {
                $rkk->ibprpLinks()->create(['risk_entry_id' => $id, 'sort_order' => $order + 1]);
            }
        });

        return $rkk->load('ibprpLinks');
    }

    /**
     * Ganti seluruh baris biaya SMKK, utuh. Setiap baris menunjuk satu baris
     * BoQ yang ADA — dan, bila RKK menyebut boq_id-nya, milik BoQ itu.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function syncSmkkCosts(RkkDocument $rkk, array $rows): RkkDocument
    {
        $prepared = [];
        $seen = [];

        foreach (array_values($rows) as $index => $row) {
            $line = $index + 1;
            $boqItemId = (int) ($row['boq_item_id'] ?? 0);

            if (in_array($boqItemId, $seen, true)) {
                throw ValidationException::withMessages([
                    "smkk_costs.{$index}.boq_item_id" => [
                        "Baris {$line}: baris BoQ #{$boqItemId} sudah tercatat sebagai biaya SMKK pada RKK ini.",
                    ],
                ]);
            }

            $item = BoqItem::query()->find($boqItemId);

            if ($item === null) {
                throw ValidationException::withMessages([
                    "smkk_costs.{$index}.boq_item_id" => [
                        "Baris {$line}: baris BoQ #{$boqItemId} tidak ditemukan; biaya SMKK harus menunjuk baris RAB yang ada.",
                    ],
                ]);
            }

            if ($rkk->boq_id !== null && (int) $item->boq_id !== (int) $rkk->boq_id) {
                throw ValidationException::withMessages([
                    "smkk_costs.{$index}.boq_item_id" => [
                        "Baris {$line}: baris BoQ #{$boqItemId} bukan milik BoQ yang dirujuk RKK ini.",
                    ],
                ]);
            }

            $seen[] = $boqItemId;

            $prepared[] = [
                'boq_item_id' => $boqItemId,
                'sort_order' => (int) ($row['sort_order'] ?? $line),
                'category' => $row['category'] ?? null,
                'notes' => $row['notes'] ?? null,
            ];
        }

        DB::transaction(function () use ($rkk, $prepared): void {
            $rkk->smkkCosts()->delete();

            foreach ($prepared as $attributes) {
                $rkk->smkkCosts()->create($attributes);
            }
        });

        return $rkk->load('smkkCosts');
    }

    /**
     * The RKK's IBPRP section, in row shape, read LIVE from the register.
     *
     * A link whose register row has since been deleted comes back with null
     * columns and available=false — the sheet rules those cells rather than
     * dropping the line, because a vanished hazard assessment is a fact about
     * the RKK and hiding it would make the sheet look complete.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ibprpRows(RkkDocument $rkk): array
    {
        $ids = $rkk->ibprpLinks->pluck('risk_entry_id')->map('intval')->all();

        if ($ids === [] || ! Schema::hasTable('prj_risk_register')) {
            return array_map(static fn (int $id): array => self::missingRiskRow($id), $ids);
        }

        $entries = DB::table('prj_risk_register')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        return array_map(static function (int $id) use ($entries): array {
            $entry = $entries->get($id);

            if ($entry === null) {
                return self::missingRiskRow($id);
            }

            return [
                'risk_entry_id' => $id,
                'available' => true,
                'activity' => $entry->activity,
                'hazard' => $entry->hazard,
                'impact' => $entry->impact,
                'likelihood' => (int) $entry->likelihood,
                'severity' => (int) $entry->severity,
                'initial_score' => (int) $entry->initial_score,
                'controls' => $entry->controls,
                'residual_score' => $entry->residual_score === null ? null : (int) $entry->residual_score,
            ];
        }, $ids);
    }

    /**
     * The SMKK cost lines with their DERIVED amounts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function smkkRows(RkkDocument $rkk): array
    {
        $rows = [];

        // Relasi yang sudah dimuat menang, alasan yang sama dengan
        // TkdnService::summary: daftar RKK meminta smkkTotal() sekali per baris.
        $costs = $rkk->relationLoaded('smkkCosts')
            ? $rkk->smkkCosts
            : $rkk->smkkCosts()->with('boqItem')->get();

        foreach ($costs as $cost) {
            $item = $cost->boqItem;

            $rows[] = [
                'boq_item_id' => (int) $cost->boq_item_id,
                'available' => $item !== null,
                'category' => $cost->category,
                'wbs_code' => $item?->wbs_code,
                'description' => $item?->description,
                'qty' => $item === null ? null : (float) $item->qty,
                'unit' => $item?->unit,
                'unit_price' => $item === null ? null : (float) $item->unit_price,
                // NULL, tidak 0: baris BoQ yang hilang menggarisi selnya.
                'amount' => $item === null ? null : (float) $item->amount,
                'notes' => $cost->notes,
            ];
        }

        return $rows;
    }

    /**
     * Kode proyek yang register risikonya menjadi sumber baris IBPRP RKK ini.
     *
     * DICETAK PADA LEMBARNYA, dan itu bukan hiasan. Sebuah RKK PENAWARAN belum
     * punya proyek — pekerjaannya belum dimenangkan — jadi baris IBPRP-nya
     * datang dari register proyek LAIN yang sejenis, yang memang cara sebuah
     * IBPRP penawaran disusun. Lembar yang mencetak bahaya-bahaya itu tanpa
     * menyebut register siapa asalnya membuat pembacanya mengira penilaian itu
     * dibuat untuk pekerjaan ini. Raw read, tanpa relasi: Crm tidak bergantung
     * ke Projects.
     */
    public function sourceProjectCode(RkkDocument $rkk): ?string
    {
        if ($rkk->project_id === null || ! Schema::hasTable('prj_projects')) {
            return null;
        }

        $project = DB::table('prj_projects')
            ->where('id', $rkk->project_id)
            ->whereNull('deleted_at')
            ->first(['code', 'name']);

        return $project === null ? null : $project->code.' — '.$project->name;
    }

    /** Jumlah biaya SMKK — hanya baris yang barisan BoQ-nya masih ada. */
    public function smkkTotal(RkkDocument $rkk): float
    {
        $total = 0.0;

        foreach ($this->smkkRows($rkk) as $row) {
            $total += (float) ($row['amount'] ?? 0);
        }

        return round($total, 2);
    }

    // ------------------------------------------------------------- internals

    /**
     * Raw, by value, behind Schema::hasTable — Projects is not a dependency of
     * Crm. Returns the subset of $ids that exists in the live register.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function liveRiskEntryIds(array $ids, ?int $projectId): array
    {
        if (! Schema::hasTable('prj_risk_register')) {
            return [];
        }

        return DB::table('prj_risk_register')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->pluck('id')
            ->map('intval')
            ->all();
    }

    /** @return array<string, mixed> */
    private static function missingRiskRow(int $id): array
    {
        return [
            'risk_entry_id' => $id,
            'available' => false,
            'activity' => null,
            'hazard' => null,
            'impact' => null,
            'likelihood' => null,
            'severity' => null,
            'initial_score' => null,
            'controls' => null,
            'residual_score' => null,
        ];
    }
}
