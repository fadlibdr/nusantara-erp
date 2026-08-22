<?php

namespace Modules\Estimation\Services;

use Illuminate\Support\Collection;
use Modules\Estimation\Enums\ComponentType;
use Modules\Estimation\Enums\CostCategory;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Models\AhspComponent;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Estimation\Models\CostBudgetItem;

/**
 * The read model behind the printed estimating forms — RAB, AHSP, RAP.
 *
 * Modules\Core\Support\PrintableDocuments declares what each sheet prints; the
 * arithmetic lives here, in the module that owns it, exactly as
 * LaporanFormService holds the two Projects forms' read model.
 *
 * WHY THE ARITHMETIC IS NOT IN THE REGISTRY ENTRY. These three sheets are
 * columns an estimator adds up with a pen. A printed column that does not foot
 * to its own printed total destroys the sheet even when every single figure
 * came out of the database — and the two places that can happen are both
 * rounding, not typing:
 *
 *   AHSP  the analysis price is round(sum(round(coef × price, 2)) × (1 + o/100), 2).
 *         Deriving the overhead line separately as D × o% can land a rupiah away
 *         from it, so the printed E is F − D and the column closes exactly.
 *   RAP   the per-category recap sums est_cost_budget_items.amount, which is the
 *         authoritative column — the same one RapService::recalcTotals sums into
 *         total_budget — never qty × unit_price, which is informative only.
 *
 * Anything the data cannot answer comes back null and the sheet rules the cell.
 * In particular a margin percentage against a BOQ worth nothing is null, not
 * 0%: "breaks even" and "cannot be computed" are different claims.
 */
class EstimationFormService
{
    /**
     * One component group of an AHSP — tenaga kerja, bahan or alat — in the
     * order the estimator entered it.
     *
     * values(): the filtered collection keeps the parent's keys, and the sheet's
     * NO column is a row counter. Without this an analysis whose bahan sit at
     * positions 2 and 5 prints rows numbered 3 and 6.
     *
     * @return Collection<int, AhspComponent>
     */
    public function ahspComponents(Ahsp $ahsp, ComponentType $type): Collection
    {
        return $ahsp->components
            ->filter(fn (AhspComponent $component): bool => $component->component_type === $type)
            ->values();
    }

    /** What one group's JUMLAH column adds up to (A, B or C on the paper). */
    public function ahspGroupTotal(Ahsp $ahsp, ComponentType $type): float
    {
        return round(
            $this->ahspComponents($ahsp, $type)->sum(fn (AhspComponent $component): float => $component->subtotal()),
            2,
        );
    }

    /**
     * The D / E / F block of SE Dirjen Bina Konstruksi 182/2025, which is the
     * shape of the owner's own AHSP spreadsheet.
     *
     * F is computed by AhspService::unitPriceOf — the one implementation of the
     * formula, and the one est_ahsp.unit_price is cached from, so the price on
     * this sheet is the price every BOQ item using this analysis was built with.
     * E is then F − D rather than D × overhead%, so A + B + C = D, D + E = F,
     * and the estimator's pen agrees with the paper.
     *
     * @return list<array{uraian: string, jumlah: float}>
     */
    public function ahspRecap(Ahsp $ahsp): array
    {
        $base = round(
            $ahsp->components->sum(fn (AhspComponent $component): float => $component->subtotal()),
            2,
        );

        $unitPrice = $this->ahspUnitPrice($ahsp);

        return [
            [
                'uraian' => 'D. Jumlah harga tenaga kerja, bahan dan peralatan (A + B + C)',
                'jumlah' => $base,
            ],
            [
                'uraian' => 'E. Overhead & profit ('.$this->percent($ahsp->overhead_pct).' × D)',
                'jumlah' => round($unitPrice - $base, 2),
            ],
            [
                'uraian' => 'F. Harga satuan pekerjaan (D + E)',
                'jumlah' => $unitPrice,
            ],
        ];
    }

    /** The analysis price, through the module's own formula and no other. */
    public function ahspUnitPrice(Ahsp $ahsp): float
    {
        return app(AhspService::class)->unitPriceOf(
            $ahsp->components->map(fn (AhspComponent $component): array => [
                'coefficient' => $component->coefficient,
                'unit_price' => $component->unit_price,
            ])->all(),
            (float) $ahsp->overhead_pct,
        );
    }

    /**
     * Every BOQ item, in bagian order, carrying the bagian it belongs to.
     *
     * The printed RAB is two tables — a rekapitulasi per bagian and this
     * rincian — rather than one table with subtotal rows inside it, because the
     * generic sheet renders a cell it cannot answer as a RULED BLANK: a section
     * heading row would print dotted rules across the volume, satuan and harga
     * columns and invite somebody to write in them.
     *
     * Assembled here rather than declared as a relation path so the bagian is
     * read off the section already loaded, instead of lazy-loading BoqItem's
     * belongsTo once per line — two hundred queries inside one print.
     *
     * @return list<array<string, mixed>>
     */
    public function rabRows(Boq $boq): array
    {
        $rows = [];

        foreach ($boq->sections as $section) {
            foreach ($section->items as $item) {
                $rows[] = [
                    'bagian' => $section->section_no,
                    'kode' => $item->wbs_code,
                    'uraian' => $item->description,
                    'volume' => $item->qty,
                    'satuan' => $item->unit,
                    'harga_satuan' => $item->unit_price,
                    'jumlah' => $item->amount,
                ];
            }
        }

        return $rows;
    }

    /**
     * The RAP's budget by cost category — material, upah, subkon, alat,
     * overhead — in the enum's own order.
     *
     * Only categories that HAVE lines are listed. A row reading "Alat 0,00" on
     * a budget with no equipment lines asserts that the equipment was costed
     * and came to nothing, which is a different statement from "this budget does
     * not break equipment out".
     *
     * @return list<array{kategori: string, porsi: ?float, jumlah: float}>
     */
    public function rapCategories(CostBudget $budget): array
    {
        $total = (float) $budget->total_budget;
        $rows = [];

        foreach (CostCategory::cases() as $category) {
            $lines = $budget->items->filter(
                fn (CostBudgetItem $item): bool => $item->cost_category === $category,
            );

            if ($lines->isEmpty()) {
                continue;
            }

            $amount = round($lines->sum(fn (CostBudgetItem $item): float => (float) $item->amount), 2);

            $rows[] = [
                'kategori' => $category->label(),
                // A share of nothing is not zero percent — it is a division
                // with no answer, and the sheet rules the cell.
                'porsi' => $total > 0 ? round($amount / $total * 100, 2) : null,
                'jumlah' => $amount,
            ];
        }

        return $rows;
    }

    /**
     * Margin rencana in rupiah: what the BOQ sells for, less what the RAP plans
     * to spend.
     *
     * null when there is nothing to compare — the BOQ has been deleted
     * (est_boqs soft-deletes) or the budget has no lines yet. A RAP created and
     * not yet generated has total_budget 0, and "margin = the whole contract
     * value" printed against it reads as a job with no costs at all rather than
     * as a budget nobody has filled in.
     */
    public function rapMarginAmount(CostBudget $budget): ?float
    {
        if (! $this->rapIsComparable($budget)) {
            return null;
        }

        return round((float) $budget->boq->total - (float) $budget->total_budget, 2);
    }

    /**
     * The same margin as a percentage — MARK-UP ON COST, which is the basis
     * est_cost_budgets.target_margin_pct itself uses.
     *
     * This is the one number on the sheet that could quietly contradict the
     * number printed two lines above it. RapService::generateFromBoq deflates
     * each BOQ amount by  amount / (1 + margin/100), so a "target margin 15%"
     * means cost × 1,15 = harga jual. Quoting the realised margin on REVENUE
     * instead — the other perfectly ordinary definition — prints 13,04% under a
     * 15% target on a budget that hit the target exactly, and the estimator
     * reading the two lines has no way to tell which of them is wrong.
     *
     * null when the budget is empty, for the reason above: a percentage of
     * nothing is a division with no answer, not zero.
     */
    public function rapMarginPct(CostBudget $budget): ?float
    {
        if (! $this->rapIsComparable($budget)) {
            return null;
        }

        $cost = (float) $budget->total_budget;

        return round(((float) $budget->boq->total - $cost) / $cost * 100, 2);
    }

    /** Both margin lines answer, or neither does. */
    private function rapIsComparable(CostBudget $budget): bool
    {
        return $budget->boq !== null && (float) $budget->total_budget > 0;
    }

    /**
     * "10%" from a stored 10,0000 — nobody writes four decimals on a form.
     *
     * The third copy of this rendering in the codebase (FormPrintService and
     * DocumentPdfService hold the others) and deliberately so: this one builds
     * a LABEL inside a printed row, which the sheet prints verbatim, and
     * reaching into the print service for it would make the Estimation module
     * depend on Core's formatter to spell its own overhead rate.
     */
    private function percent(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',').'%';
    }
}
