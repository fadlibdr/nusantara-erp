<?php

namespace Modules\Estimation\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Enums\CostCategory;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Estimation\Models\CostBudget;

class RapService
{
    public function create(array $data): CostBudget
    {
        $boq = Boq::query()->findOrFail($data['boq_id']);

        return CostBudget::query()->create([
            'code' => $data['code'] ?? null, // null => HasDocumentNumber assigns RAP/{Y}/{N4}
            'boq_id' => $boq->id,
            'project_id' => $data['project_id'] ?? $boq->project_id,
            'target_margin_pct' => $data['target_margin_pct'],
            'status' => DocumentStatus::Draft,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Header fields of an existing RAP, and its lines when the caller carries them.
     *
     * The counterpart of BoqService::update: `items` is replaced WHOLESALE when
     * the key is present and left untouched when it is absent, so a caller that
     * only means to move the margin cannot silently empty the budget.
     *
     * boq_id is deliberately not fillable here. Re-parenting a RAP would leave
     * every est_cost_budget_items.boq_item_id pointing into a BOQ the budget no
     * longer belongs to, and prj_baselines resolves a project's RAP by
     * project_id — so a variance report would compare against a different
     * bill of quantities without one screen saying so.
     */
    public function update(CostBudget $budget, array $data): CostBudget
    {
        $this->assertEditable($budget, 'edited');

        return DB::transaction(function () use ($budget, $data): CostBudget {
            $budget->fill([
                // A blank proyek_kode means "the BOQ's project", never "no
                // project": BaselineService finds a project's RAP with
                // where('project_id', …), so nulling it detaches the budget from
                // every baseline and EVM report that would have found it, while
                // the RAP itself still looks complete on its own screen.
                'project_id' => $data['project_id'] ?? $budget->boq()->value('project_id'),
                'target_margin_pct' => $data['target_margin_pct'] ?? $budget->target_margin_pct,
                'notes' => $data['notes'] ?? $budget->notes,
            ])->save();

            if (array_key_exists('items', $data)) {
                $this->replaceItems($budget, $data['items'] ?? []);
            }

            return $budget->refresh();
        });
    }

    /**
     * Replace every line of a manually-costed RAP.
     *
     * The manual counterpart of generateFromBoq, and it carries the SAME
     * editability guard for a reason that is not symmetry: the RAP's other
     * editability check lives in CostBudgetController::update, not in this
     * service, so a service-level write path without a guard of its own would
     * make any caller — the document importer above all — the supported way to
     * rewrite an approved RAP and move the budget a project is measured against.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function replaceItems(CostBudget $budget, array $lines): CostBudget
    {
        $this->assertEditable($budget, 'rewritten');

        return DB::transaction(function () use ($budget, $lines): CostBudget {
            $budget->items()->delete();

            foreach ($lines as $line) {
                $qty = round((float) ($line['qty'] ?? 0), 3);
                $unitPrice = round((float) ($line['unit_price'] ?? 0), 2);

                $budget->items()->create([
                    'boq_item_id' => $line['boq_item_id'],
                    'cost_category' => $line['cost_category'],
                    'description' => $line['description'],
                    'qty' => $qty,
                    'unit' => $line['unit'],
                    'unit_price' => $unitPrice,
                    // amount is authoritative — recalcTotals and EvmService's
                    // cost coverage both sum this column, never qty x price.
                    'amount' => round($qty * $unitPrice, 2),
                ]);
            }

            return $this->recalcTotals($budget);
        });
    }

    /**
     * Reasons, in Indonesian, why replacing this RAP's lines would move a number
     * somebody else already froze.
     *
     * prj_baselines stores bac + cost_budget_id, but EvmService::costCoverage
     * reads est_cost_budget_items LIVE by that id to get the per-category
     * budget. An approved baseline is frozen by ProjectBaseline::isFrozen(), and
     * yet its RAP may still be a draft — BacSource::RapUnapproved exists exactly
     * for that case — so rewriting the draft silently changes what a frozen
     * baseline's CPI coverage is measured against, and the baseline goes on
     * reporting a BAC that no longer matches its own lines.
     *
     * @return array<int, string>
     */
    public function dependencyBlockers(CostBudget $budget): array
    {
        $frozen = DB::table('prj_baselines')
            ->where('cost_budget_id', $budget->id)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('superseded_at')
            ->pluck('code')
            ->all();

        if ($frozen === []) {
            return [];
        }

        return [sprintf(
            'baseline %s sudah dibekukan terhadap RAP ini; mengganti rinciannya akan mengubah acuan biaya laporan EVM.'
            .' Buat baseline revisi baru lalu impor ke RAP-nya.',
            implode(', ', $frozen),
        )];
    }

    /**
     * (Re)build the RAP lines from the linked BOQ.
     *
     * Per BOQ item the internal budget is the selling amount deflated by the
     * target margin:  budget = amount / (1 + margin / 100).
     * When the item has an AHSP analysis, the budget is split across cost
     * categories proportionally to the component mix (labor / material /
     * equipment) plus the AHSP overhead share; otherwise it becomes a single
     * lump-sum line (assumed subcontracted scope).
     */
    public function generateFromBoq(CostBudget $budget, ?float $marginPct = null): CostBudget
    {
        $this->assertEditable($budget, 'regenerated');

        $margin = $marginPct ?? (float) $budget->target_margin_pct;

        if ($margin <= -100) {
            throw new LogicException('Target margin must be greater than -100%.');
        }

        return DB::transaction(function () use ($budget, $margin): CostBudget {
            $budget->forceFill(['target_margin_pct' => $margin])->save();
            $budget->items()->delete();

            $boq = $budget->boq()->with(['items.ahsp.components'])->firstOrFail();

            foreach ($boq->items as $item) {
                $target = round((float) $item->amount / (1 + $margin / 100), 2);

                foreach ($this->splitBudget($item, $target) as $line) {
                    $budget->items()->create($line + ['boq_item_id' => $item->id]);
                }
            }

            return $this->recalcTotals($budget);
        });
    }

    public function recalcTotals(CostBudget $budget): CostBudget
    {
        $total = (float) $budget->items()->sum('amount');

        $budget->forceFill(['total_budget' => round($total, 2)])->save();

        return $budget;
    }

    /**
     * One guard for every path that writes a RAP's lines.
     *
     * $action only names the attempt; the sentence is otherwise identical
     * whichever door was tried, so an approved RAP refuses the same way through
     * the importer as it does through the generator.
     */
    private function assertEditable(CostBudget $budget, string $action): void
    {
        if (! $budget->status->isEditable()) {
            throw new LogicException("RAP {$budget->code} cannot be {$action} while status is {$budget->status->value}.");
        }
    }

    /**
     * Split one BOQ item's budget into RAP lines per cost category.
     *
     * @return array<int, array<string, mixed>>
     */
    private function splitBudget(BoqItem $item, float $target): array
    {
        $ahsp = $item->ahsp;
        $qty = (float) $item->qty;

        if ($ahsp === null || $ahsp->components->isEmpty()) {
            return [$this->line(CostCategory::Subcon, $item, $qty, $target)];
        }

        // Base cost per component type from the AHSP analysis.
        $bases = [];
        foreach ($ahsp->components as $component) {
            $category = $component->component_type->costCategory()->value;
            $bases[$category] = ($bases[$category] ?? 0.0)
                + (float) $component->coefficient * (float) $component->unit_price;
        }
        $bases = array_filter($bases, fn (float $base): bool => $base > 0);

        $baseTotal = array_sum($bases);
        if ($baseTotal <= 0) {
            return [$this->line(CostCategory::Subcon, $item, $qty, $target)];
        }

        $overheadBase = $baseTotal * (float) $ahsp->overhead_pct / 100;
        $grand = $baseTotal + $overheadBase;

        $lines = [];
        $allocated = 0.0;
        $largestKey = null;

        foreach ($bases as $category => $base) {
            $amount = round($target * $base / $grand, 2);
            $allocated += $amount;
            $lines[$category] = $this->line(CostCategory::from($category), $item, $qty, $amount);

            if ($largestKey === null || $base > $bases[$largestKey]) {
                $largestKey = $category;
            }
        }

        // Whatever is not allocated to direct categories is the overhead share
        // plus rounding remainders — so category lines always sum to the target.
        $remainder = round($target - $allocated, 2);

        if ($overheadBase > 0) {
            $lines[CostCategory::Overhead->value] = $this->line(CostCategory::Overhead, $item, $qty, $remainder);
        } elseif ($remainder != 0.0) {
            $adjusted = round((float) $lines[$largestKey]['amount'] + $remainder, 2);
            $lines[$largestKey]['amount'] = $adjusted;
            $lines[$largestKey]['unit_price'] = $qty > 0 ? round($adjusted / $qty, 2) : $adjusted;
        }

        return array_values($lines);
    }

    private function line(CostCategory $category, BoqItem $item, float $qty, float $amount): array
    {
        return [
            'cost_category' => $category->value,
            'description' => $item->description.' ('.$category->label().')',
            'qty' => $qty,
            'unit' => $item->unit,
            // amount is authoritative; unit_price is informative (amount / qty).
            'unit_price' => $qty > 0 ? round($amount / $qty, 2) : $amount,
            'amount' => $amount,
        ];
    }
}
