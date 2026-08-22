<?php

namespace Modules\Estimation\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Estimation\Models\BoqSection;
use Modules\Estimation\Models\CostBudget;

class BoqService
{
    public function create(array $data): Boq
    {
        return DB::transaction(function () use ($data): Boq {
            $boq = Boq::query()->create([
                'code' => $data['code'] ?? null, // null => HasDocumentNumber assigns BOQ/{Y}/{N4}
                'project_id' => $data['project_id'] ?? null,
                'quotation_id' => $data['quotation_id'] ?? null,
                'contract_id' => $data['contract_id'] ?? null,
                'title' => $data['title'],
                'version' => $data['version'] ?? 1,
                'status' => DocumentStatus::Draft,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->replaceSections($boq, $data['sections'] ?? []);

            return $boq;
        });
    }

    public function update(Boq $boq, array $data): Boq
    {
        if (! $boq->status->isEditable()) {
            throw new LogicException("BOQ {$boq->code} cannot be edited while status is {$boq->status->value}.");
        }

        return DB::transaction(function () use ($boq, $data): Boq {
            $boq->fill(collect($data)
                ->only(['project_id', 'quotation_id', 'contract_id', 'title', 'notes'])
                ->all())->save();

            // Sections + items are replaced wholesale when the key is present.
            if (array_key_exists('sections', $data)) {
                $this->replaceSections($boq, $data['sections'] ?? []);
            }

            return $boq->refresh();
        });
    }

    /**
     * Reasons, in Indonesian, why replacing this BOQ's sections would destroy
     * something that lives OUTSIDE Estimation.
     *
     * replaceSections() deletes the sections, and est_boq_items hangs off them
     * with cascadeOnDelete, so everything that points AT an item goes too:
     *
     *   est_cost_budget_items.boq_item_id — constrained, cascadeOnDelete. The
     *      lines of every RAP built from this BOQ are DELETED. A submitted or
     *      approved RAP cannot be rebuilt (generateFromBoq refuses it), so its
     *      budget would simply become zero while the document still reads as
     *      approved.
     *   prj_wbs_tasks.boq_item_id — a bare cross-module column with no
     *      constraint, so those links DANGLE rather than disappear.
     *      MaterialVarianceService reads est_boq_items through it for the theory
     *      quantity of every leaf task, and a dangling id computes NO theory:
     *      the report keeps working and quietly reports less material than the
     *      job needs. EvmService loses the same linkage.
     *   prc_purchase_requisition_items.boq_item_id and
     *   scm_subcontract_items.boq_item_id — the same bare column, so a PR's
     *      budget line and an SPK's origin line stop tracing back to the work
     *      they were raised for.
     *
     * Read-only: it answers, it never fixes. The document importer calls it per
     * document and refuses the whole document on any answer, which is the only
     * safe outcome when one upload replaces 400 lines at once.
     *
     * This does NOT change what update() itself does. PUT /boqs/{id} carries the
     * identical hazard one form at a time and is a follow-up of its own; closing
     * it here would move behaviour several suites already assert.
     *
     * @return array<int, string>
     */
    public function dependencyBlockers(Boq $boq): array
    {
        $itemIds = $boq->items()->pluck('id');

        if ($itemIds->isEmpty()) {
            return [];
        }

        $blockers = [];

        foreach ($this->boundCostBudgets($itemIds->all()) as $budget) {
            // A draft RAP can be regenerated from the BOQ afterwards, so losing
            // its lines is recoverable and only warns (see dependencyWarnings).
            if (! $budget->status->isEditable()) {
                $blockers[] = "RAP {$budget->code} ({$budget->status->label()}) dibuat dari BOQ ini dan seluruh barisnya akan"
                    .' terhapus; buat Versi Baru BOQ lalu impor ke versi itu.';
            }
        }

        foreach ([
            'prj_wbs_tasks' => 'tugas WBS proyek',
            'prc_purchase_requisition_items' => 'baris permintaan pembelian (PR)',
            'scm_subcontract_items' => 'baris SPK subkontraktor',
        ] as $table => $label) {
            $count = DB::table($table)->whereIn('boq_item_id', $itemIds)->count();

            if ($count > 0) {
                $blockers[] = "{$count} {$label} menunjuk baris BOQ ini; menggantinya memutus tautan itu tanpa jejak"
                    .' — laporan varian material kehilangan kuantitas teorinya. Buat Versi Baru BOQ lalu impor ke versi itu.';
            }
        }

        return $blockers;
    }

    /**
     * The same dependency, where it is recoverable rather than fatal.
     *
     * A draft RAP loses its lines too, but generateFromBoq builds them back from
     * the new BOQ — so this is the one case that must not block an estimator who
     * is still pricing. It has to be SAID, though: a RAP left un-regenerated
     * reads Rp 0 and every EVM report against it is wrong until somebody notices.
     *
     * @return array<int, string>
     */
    public function dependencyWarnings(Boq $boq): array
    {
        $warnings = [];
        $itemIds = $boq->items()->pluck('id');

        if ($itemIds->isEmpty()) {
            return $warnings;
        }

        foreach ($this->boundCostBudgets($itemIds->all()) as $budget) {
            if ($budget->status->isEditable()) {
                $warnings[] = "RAP {$budget->code} dibuat dari BOQ ini; barisnya akan terhapus dan harus dibuat ulang"
                    .' (Generate dari BOQ) setelah impor selesai.';
            }
        }

        return $warnings;
    }

    /**
     * Item numbers that appear more than once in one BOQ payload.
     *
     * Legal at the database level and harmless to this BOQ's own arithmetic,
     * which is why it warns rather than refuses. It is worth saying because
     * everything that points at a BOQ line resolves it BY NUMBER: a RAP import
     * refuses an ambiguous `item_boq` outright rather than binding the cost of a
     * whole package to whichever A.1 it met first, so a duplicate planted here
     * surfaces days later as a RAP that will not load.
     *
     * Takes the sections payload replaceSections() itself accepts, so it stays
     * usable by any caller that assembles one.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, string>
     */
    public function duplicateWbsCodes(array $sections): array
    {
        $counts = [];

        foreach ($sections as $section) {
            foreach ($section['items'] ?? [] as $item) {
                $code = trim((string) ($item['wbs_code'] ?? ''));

                if ($code !== '') {
                    $counts[$code] = ($counts[$code] ?? 0) + 1;
                }
            }
        }

        $duplicates = array_keys(array_filter($counts, fn (int $count): bool => $count > 1));

        return $duplicates === [] ? [] : [
            'nomor item berulang di dalam satu BOQ: '.implode(', ', $duplicates)
            .'. Impor tetap dilanjutkan, tetapi RAP dan tugas WBS mencari baris BOQ menurut nomornya'
            .' dan akan menolak nomor ganda.',
        ];
    }

    /**
     * What the imported sheet is worth once the AHSP-priced lines are costed.
     *
     * addItem() takes description, unit and PRICE from the analysis when a line
     * carries ahsp_id and no harga_satuan — which is the workflow the template's
     * own worked example advertises ("baris item boleh hanya berisi nomor +
     * ahsp_kode + volume"). The importer's per-document total, however, is
     * volume x the unit price the SHEET carried, and that cell is empty for
     * exactly those lines: a BOQ that commits at Rp 162.591.000 previews as
     * Rp 0. On the one screen an estimator uses to check a bid before saving it,
     * a confident Rp 0 is not "not computed yet" — it is a wrong number, and the
     * preview's whole reason for existing is that the operator can check the
     * arithmetic. So the analysis prices are resolved here and the sheet's total
     * is restated with them, naming the count so the two numbers can be told
     * apart.
     *
     * Read-only, and shaped like duplicateWbsCodes(): it takes the sections
     * payload replaceSections() accepts, so any caller that assembles one can
     * ask.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, string> at most one warning
     */
    public function analysisPricedWarnings(array $sections): array
    {
        $lines = 0;
        $inherited = [];
        $stated = 0.0;

        foreach ($sections as $section) {
            foreach ($section['items'] ?? [] as $item) {
                $lines++;
                $qty = (float) ($item['qty'] ?? 0);

                // isset(), exactly as addItem() decides it: a present-but-null
                // harga_satuan is still "price it from the analysis".
                if (isset($item['unit_price'])) {
                    $stated += round($qty * (float) $item['unit_price'], 2);

                    continue;
                }

                if (! empty($item['ahsp_id'])) {
                    $inherited[] = ['ahsp_id' => (int) $item['ahsp_id'], 'qty' => $qty];
                }
            }
        }

        if ($inherited === []) {
            return [];
        }

        // One query for the whole document; the ids are already resolved (an
        // unknown ahsp_kode refuses the line long before this runs).
        $prices = Ahsp::query()
            ->whereIn('id', array_unique(array_column($inherited, 'ahsp_id')))
            ->pluck('unit_price', 'id');

        $value = 0.0;

        foreach ($inherited as $line) {
            $value += round($line['qty'] * (float) ($prices[$line['ahsp_id']] ?? 0), 2);
        }

        return [sprintf(
            '%d dari %d baris pekerjaan dihargai dari analisa AHSP (kolom harga_satuan kosong):'
            .' harganya baru diambil dari analisa saat disimpan, sehingga total impor yang tertera'
            .' (Rp %s) BELUM memuatnya. Baris-baris itu bernilai Rp %s, jadi nilai BOQ ini setelah'
            .' disimpan adalah Rp %s.',
            count($inherited),
            $lines,
            number_format($stated, 2, ',', '.'),
            number_format(round($value, 2), 2, ',', '.'),
            number_format(round($stated + $value, 2), 2, ',', '.'),
        )];
    }

    /**
     * Every live RAP holding a line against one of these BOQ items.
     *
     * @param  array<int, int>  $itemIds
     * @return Collection<int, CostBudget>
     */
    private function boundCostBudgets(array $itemIds): Collection
    {
        return CostBudget::query()
            ->whereIn('id', DB::table('est_cost_budget_items')
                ->whereIn('boq_item_id', $itemIds)
                ->distinct()
                ->pluck('cost_budget_id'))
            ->orderBy('code')
            ->get();
    }

    public function replaceSections(Boq $boq, array $sections): void
    {
        // Cascades to items (and to derived RAP lines) at the DB level.
        $boq->sections()->delete();

        foreach ($sections as $index => $sectionData) {
            $section = $boq->sections()->create([
                'section_no' => $sectionData['section_no'],
                'name' => $sectionData['name'],
                'sort_order' => $sectionData['sort_order'] ?? $index + 1,
            ]);

            foreach ($sectionData['items'] ?? [] as $itemIndex => $line) {
                $this->addItem($boq, $section, $line, $itemIndex + 1);
            }
        }

        $this->recalcTotals($boq);
    }

    /**
     * Add one item; when ahsp_id is set, unit / unit_price / description default
     * to the AHSP analysis so estimators only type the quantity.
     */
    public function addItem(Boq $boq, BoqSection $section, array $line, int $defaultSortOrder = 0): BoqItem
    {
        $ahsp = ! empty($line['ahsp_id']) ? Ahsp::query()->find($line['ahsp_id']) : null;

        $qty = (float) ($line['qty'] ?? 0);
        $unitPrice = isset($line['unit_price'])
            ? (float) $line['unit_price']
            : (float) ($ahsp?->unit_price ?? 0);

        return $boq->items()->create([
            'section_id' => $section->id,
            'wbs_code' => $line['wbs_code'],
            'description' => $line['description'] ?? $ahsp?->name ?? '',
            'ahsp_id' => $ahsp?->id,
            'qty' => $qty,
            'unit' => $line['unit'] ?? $ahsp?->unit ?? 'ls',
            'unit_price' => round($unitPrice, 2),
            'amount' => round($qty * $unitPrice, 2),
            'sort_order' => $line['sort_order'] ?? $defaultSortOrder,
        ]);
    }

    /**
     * Append items built from AHSP analyses into an existing section.
     * Each line: ['ahsp_id' => .., 'wbs_code' => .., 'qty' => .., 'description'? , 'sort_order'?].
     *
     * @return BoqItem[]
     */
    public function importItemsFromAhsp(Boq $boq, BoqSection $section, array $lines): array
    {
        return DB::transaction(function () use ($boq, $section, $lines): array {
            $created = [];

            foreach ($lines as $index => $line) {
                $created[] = $this->addItem($boq, $section, $line, $index + 1);
            }

            $this->recalcTotals($boq);

            return $created;
        });
    }

    /**
     * Recompute every section subtotal and the BOQ grand total from the items.
     */
    public function recalcTotals(Boq $boq): Boq
    {
        $total = 0.0;

        foreach ($boq->sections()->get() as $section) {
            $subtotal = (float) $boq->items()
                ->where('section_id', $section->id)
                ->sum('amount');

            $section->forceFill(['subtotal' => round($subtotal, 2)])->save();
            $total += $subtotal;
        }

        $boq->forceFill(['total' => round($total, 2)])->save();

        return $boq;
    }

    /**
     * Clone a BOQ into a fresh draft with version + 1 (revisi RAB).
     * The copy gets its own document number; sections, items and AHSP links carry over.
     */
    public function copyVersion(Boq $boq): Boq
    {
        return DB::transaction(function () use ($boq): Boq {
            $new = $boq->replicate(['code', 'status', 'version', 'total']);
            $new->code = null; // HasDocumentNumber assigns the next BOQ number
            $new->status = DocumentStatus::Draft;
            $new->version = (int) $boq->version + 1;
            $new->save();

            foreach ($boq->sections()->get() as $section) {
                $newSection = $new->sections()->create([
                    'section_no' => $section->section_no,
                    'name' => $section->name,
                    'sort_order' => $section->sort_order,
                ]);

                foreach ($section->items()->get() as $item) {
                    $new->items()->create([
                        'section_id' => $newSection->id,
                        'wbs_code' => $item->wbs_code,
                        'description' => $item->description,
                        'ahsp_id' => $item->ahsp_id,
                        'qty' => $item->qty,
                        'unit' => $item->unit,
                        'unit_price' => $item->unit_price,
                        'amount' => $item->amount,
                        'sort_order' => $item->sort_order,
                    ]);
                }
            }

            return $this->recalcTotals($new);
        });
    }
}
