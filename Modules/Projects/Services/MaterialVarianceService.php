<?php

namespace Modules\Projects\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Core\Support\Money;
use Modules\Estimation\Enums\ComponentType;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;

/**
 * Varian material — teori (koefisien AHSP x volume BOQ) versus aktual (bon
 * gudang), per item per paket pekerjaan.
 *
 * The two numbers never met on any screen before this. The RAB for
 * PRJ-2026-001 orders 948.000 kg of besi beton ulir at an AHSP coefficient of
 * 1,05 kg/kg — 995.400 kg, Rp 12,44 miliar of steel theory on one work
 * package — while the bon that draws the steel down lives in inv_issue_items
 * and was never subtracted from it.
 *
 * THEORY, per LEAF prj_wbs_tasks row with a boq_item_id: est_boq_items.qty x
 * est_ahsp_components.coefficient, over components with component_type
 * 'material' AND item_id set. A component with no item_id ('Kawat beton',
 * 'Conduit PVC 20mm') can never be matched to a bon line, so it is skipped
 * and NAMED in a warning — never counted as a zero-actual overrun. Theory is
 * valued at the AHSP component's unit_price (the budgeted price); basis
 * 'progress' multiplies by the task's live progress_pct, basis 'full' does not.
 *
 * ACTUAL, per inv_issue_items row whose parent issue is POSTED: the line's
 * amount, which is moving-average cost at issue time. The LINE's wbs_task_id
 * is the authority, never the issue header's — the header value is a bulk
 * default the line may override. A line with no wbs_task_id lands in the
 * explicit "bon belum ditandai" bucket, never silently in a package and never
 * dropped: on the live demo that bucket is 100% (Rp 18.740.000 of
 * Rp 18.740.000), and that figure is the report's headline warning, not a
 * blemish to smooth over.
 *
 * UNITS ARE NEVER FORCED EQUAL. The AHSP measures besi in kg, the warehouse
 * stocks it in btg; such a row reports variance_qty null with note
 * 'satuan_berbeda'. The rupiah variance is still computed — money adds up
 * whatever the unit.
 *
 * STRICTLY READ-ONLY. It SELECTs Estimation and Inventory tables and writes
 * nothing anywhere: no journal, no stock movement, no update to any bon.
 */
class MaterialVarianceService
{
    /**
     * How many "bon belum ditandai" detail rows travel to the screen. The TRUE
     * count and value always go in the summary — the screen prints
     * "50 dari 120 baris ditampilkan" from the difference.
     */
    private const UNATTRIBUTED_PAGE = 50;

    /**
     * The full report for one project — exactly the payload
     * public/app/js/views/varian.js consumes, field for field.
     */
    public function report(Project $project, ?string $asOf = null, string $basis = 'progress'): array
    {
        $asOf = $this->resolveAsOf($asOf);
        $basis = $this->resolveBasis($basis);

        $warnings = [];

        if ($asOf < now()->toDateString()) {
            // Honest limitation, stated rather than hidden: per-task progress
            // history is not stored per date, so a backdated report compares
            // yesterday's bon against TODAY's progress-based theory.
            $warnings[] = sprintf(
                'Laporan mundur: kolom aktual berhenti pada %s, tetapi teori memakai progres paket SAAT INI '
                .'karena riwayat progres per paket tidak disimpan per tanggal.',
                $asOf,
            );
        }

        $tasks = $project->wbsTasks()->orderBy('sort_order')->orderBy('wbs_code')->get();
        $parentIds = $tasks->pluck('parent_id')->filter()->unique();
        $leaves = $tasks->filter(fn (WbsTask $task): bool => ! $parentIds->contains($task->id))->values();

        $boq = $project->boq()->first();

        if ($boq !== null && $boq->status !== DocumentStatus::Approved) {
            $warnings[] = sprintf(
                'RAB %s berstatus %s — teori bahan di bawah dibaca dari dokumen yang belum disetujui.',
                $boq->code,
                $boq->status->label(),
            );
        }

        [$theoryByKey, $tasksWithTheory, $noTheoryNote] = $this->theory($boq, $leaves, $basis, $warnings);
        [$actualByKey, $unattributed, $issueTotal] = $this->actuals($project, $tasks, $asOf, $warnings);

        $thresholds = [
            'pct' => Erp::float('projects.material_variance_pct_threshold', 5.0),
            'value' => Erp::float('projects.material_variance_value_threshold', 5_000_000),
            'always_show_value' => Erp::float('projects.material_variance_always_show_value', 50_000_000),
        ];

        $rows = $this->mergeRows($tasks, $theoryByKey, $actualByKey, $noTheoryNote, $thresholds);

        $theoryTotal = round(array_sum(array_map(fn (array $row): float => (float) ($row['theory_value'] ?? 0), $rows)), 2);
        $actualTotal = round(array_sum(array_map(fn (array $row): float => (float) $row['actual_value'], $rows)), 2);

        $noBoq = $boq === null;

        return [
            'as_of' => $asOf,
            'as_of_source' => 'server',
            'basis' => $basis,
            // A project with no linked RAB is an ordinary state of the world,
            // not an error — but the report SAYS so, because a theory column
            // silently reading zero would look like perfect thrift.
            'state' => $noBoq ? 'no_boq' : 'ok',
            'message' => $noBoq
                ? sprintf(
                    'Proyek %s tidak terhubung ke RAB/BOQ mana pun, sehingga teori bahan tidak dapat dihitung. '
                    .'Kolom teori dikosongkan — kosong bukan nol. Bon yang sudah keluar tetap dihitung di bawah.',
                    $project->code,
                )
                : null,
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
            ],
            'theory_source' => $noBoq ? null : [
                'boq_id' => $boq->id,
                'boq_code' => $boq->code,
                'boq_status' => $boq->status->value,
            ],
            'summary' => [
                // null, not 0: "belum dihitung" and "nol rupiah" are two
                // different statements and the screen renders them differently.
                'theory_value' => $noBoq ? null : $theoryTotal,
                'actual_value' => $actualTotal,
                'variance_value' => $noBoq ? null : round($actualTotal - $theoryTotal, 2),
                'variance_pct' => ($noBoq || $theoryTotal <= 0)
                    ? null
                    : $this->finite(($actualTotal - $theoryTotal) / $theoryTotal * 100, 2),
                'unattributed_value' => $unattributed['value'],
                'unattributed_issue_pct' => $issueTotal > 0
                    ? $this->finite($unattributed['value'] / $issueTotal * 100, 2)
                    : null,
                'unattributed_line_count' => $unattributed['count'],
                'leaf_task_count' => $leaves->count(),
                'tasks_with_theory' => $tasksWithTheory,
            ],
            'rows' => $rows,
            'unattributed' => $unattributed['rows'],
            'thresholds' => $thresholds,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    // ---------------------------------------------------------------- inputs

    /**
     * AS-OF COMES FROM THE SERVER, exactly as EvmService::resolveAsOf: empty
     * means "today according to the server", and a future date is refused
     * rather than clamped — a future cut-off would count bon that exist today
     * against theory for work not yet due.
     */
    private function resolveAsOf(?string $asOf): string
    {
        $today = now()->toDateString();

        if ($asOf === null || trim($asOf) === '') {
            return $today;
        }

        $asOf = Carbon::parse($asOf)->toDateString();

        if ($asOf > $today) {
            throw new LogicException(
                'Tanggal laporan tidak boleh di masa depan — bon yang ada hari ini akan dibandingkan '
                .'dengan teori pekerjaan yang belum jatuh tempo.'
            );
        }

        return $asOf;
    }

    /**
     * Refused, not coerced: a typo like 'ful' silently read as 'progress'
     * would print a theory column measured on a basis nobody asked for.
     */
    private function resolveBasis(?string $basis): string
    {
        if ($basis === null || trim($basis) === '') {
            return 'progress';
        }

        if (! in_array($basis, ['progress', 'full'], true)) {
            throw new LogicException(sprintf(
                "Dasar perhitungan '%s' tidak dikenal — pilih 'progress' (teori sampai progres paket) "
                ."atau 'full' (volume kontrak penuh).",
                $basis,
            ));
        }

        return $basis;
    }

    // ---------------------------------------------------------------- theory

    /**
     * Theory lines keyed "{task_id}:{item_id}", plus how many leaves produced
     * any, plus the note explaining WHY a leaf produced none — the note an
     * actual-only row on that task will carry.
     *
     * On the live demo only B.2 (Ready Mix, 8.200 m3 x 1,02 = 8.364 m3 =
     * Rp 9.618.600.000 full) and B.3 (Besi, 948.000 kg x 1,05 = 995.400 kg =
     * Rp 12.442.500.000 full) can produce material theory: A.1/A.2/B.4 have no
     * AHSP, B.1's AHSP is labour only, C.1/C.2 carry boq_item_id NULL.
     *
     * @param  Collection<int, WbsTask>  $leaves
     * @param  list<string>  $warnings
     * @return array{0: array<string, array>, 1: int, 2: array<int, string>}
     */
    private function theory(?Boq $boq, Collection $leaves, string $basis, array &$warnings): array
    {
        $theory = [];
        $tasksWithTheory = 0;
        $noTheoryNote = [];
        $skippedCount = 0;
        $skippedValue = 0.0;
        $skippedExample = null;

        $boqItems = $boq === null
            ? collect()
            : BoqItem::query()
                ->whereIn('id', $leaves->pluck('boq_item_id')->filter()->unique())
                ->with('ahsp.components')
                ->get()
                ->keyBy('id');

        foreach ($leaves as $task) {
            $item = $task->boq_item_id === null ? null : $boqItems->get($task->boq_item_id);

            if ($boq === null || $item === null) {
                // Not linked to the RAB at all — the C.1/C.2 case. The
                // coverage sentence on screen counts these from
                // tasks_with_theory vs leaf_task_count.
                $noTheoryNote[$task->id] = 'tanpa_teori';

                continue;
            }

            if ($item->boq_id !== $boq->id) {
                // Data poison: the task points at a BOQ line of a DIFFERENT
                // document. Computing theory from it would price this project's
                // work with another project's volumes.
                $noTheoryNote[$task->id] = 'tanpa_teori';
                $warnings[] = sprintf(
                    'Paket %s menunjuk baris BOQ milik dokumen lain (bukan %s); teorinya tidak dihitung.',
                    $task->wbs_code,
                    $boq->code,
                );

                continue;
            }

            $ahsp = $item->ahsp;
            $factor = $basis === 'progress'
                ? min(100.0, max(0.0, (float) $task->progress_pct)) / 100
                : 1.0;
            $produced = false;

            foreach ($ahsp?->components ?? [] as $component) {
                if ($component->component_type !== ComponentType::Material) {
                    continue;
                }

                $qty = (float) $item->qty * (float) $component->coefficient * $factor;
                $value = $qty * (float) $component->unit_price;

                if ($component->item_id === null) {
                    // 'Kawat beton': real budgeted money that no bon can ever
                    // be matched against. Skipped from the rows, named below.
                    $skippedCount++;
                    $skippedValue += $value;
                    $skippedExample ??= $component->name;

                    continue;
                }

                $key = $task->id.':'.$component->item_id;
                $entry = $theory[$key] ?? [
                    'item_id' => (int) $component->item_id,
                    'component_name' => $component->name,
                    'theory_qty' => 0.0,
                    'theory_unit' => $component->unit,
                    'theory_value' => 0.0,
                ];
                $entry['theory_qty'] += $qty;
                $entry['theory_value'] += $value;
                $theory[$key] = $entry;
                $produced = true;
            }

            if ($produced) {
                $tasksWithTheory++;
            } else {
                // A BOQ line whose AHSP is missing or lists no stocked
                // material (labour/equipment only, like B.1's galian).
                $noTheoryNote[$task->id] = 'tanpa_ahsp';
            }
        }

        if ($skippedCount > 0) {
            $warnings[] = sprintf(
                '%d komponen bahan pada AHSP tidak menunjuk item persediaan (mis. %s) senilai %s pada dasar ini; '
                .'komponen itu tidak pernah bisa dicocokkan dengan bon, jadi sengaja tidak masuk teori.',
                $skippedCount,
                $skippedExample,
                Money::format(round($skippedValue, 2), false),
            );
        }

        return [$theory, $tasksWithTheory, $noTheoryNote];
    }

    // ---------------------------------------------------------------- actual

    /**
     * Attributed lines keyed "{task_id}:{item_id}", the unattributed bucket,
     * and the total bon value (both together) that anchors
     * unattributed_issue_pct.
     *
     * Posted issues only — a draft bon has moved no stock. Reads the LINE's
     * wbs_task_id, never the header's. Guarded by Schema::hasTable the way
     * Inventory's own requests guard cross-module lookups, so Projects still
     * answers (theory only) on an install without Inventory.
     *
     * @param  Collection<int, WbsTask>  $tasks
     * @param  list<string>  $warnings
     * @return array{0: array<string, array>, 1: array{rows: list<array>, value: float, count: int}, 2: float}
     */
    private function actuals(Project $project, Collection $tasks, string $asOf, array &$warnings): array
    {
        $empty = ['rows' => [], 'value' => 0.0, 'count' => 0];

        if (! Schema::hasTable('inv_issues')
            || ! Schema::hasTable('inv_issue_items')
            || ! Schema::hasColumn('inv_issue_items', 'wbs_task_id')) {
            $warnings[] = 'Modul Inventory tidak tersedia pada instalasi ini, sehingga kolom aktual '
                .'dan daftar bon belum ditandai kosong — laporan ini hanya menampilkan teori.';

            return [[], $empty, 0.0];
        }

        $lines = DB::table('inv_issue_items as l')
            ->join('inv_issues as i', 'i.id', '=', 'l.issue_id')
            ->leftJoin('inv_items as it', 'it.id', '=', 'l.item_id')
            ->where('i.project_id', $project->id)
            ->where('i.status', StockDocumentStatus::Posted->value)
            ->whereNull('i.deleted_at')
            // whereDate, the house lesson: the date column holds
            // '2026-07-05 00:00:00' on SQLite, and a raw string <= drops the
            // Rp 18.740.000 bon dated on the cut-off day itself.
            ->whereDate('i.issue_date', '<=', $asOf)
            ->orderBy('i.issue_date')
            ->orderBy('l.id')
            ->get([
                'l.item_id', 'l.qty', 'l.amount', 'l.wbs_task_id',
                'i.id as issue_id', 'i.code as issue_code', 'i.issue_date',
                'it.code as item_code', 'it.name as item_name', 'it.unit as item_unit',
            ]);

        $taskIds = $tasks->pluck('id')->all();
        $actual = [];
        $unattributedRows = [];
        $unattributedValue = 0.0;
        $unattributedCount = 0;
        $issueTotal = 0.0;
        $foreignIssueCodes = [];

        foreach ($lines as $line) {
            $issueTotal += (float) $line->amount;

            $taskId = $line->wbs_task_id === null ? null : (int) $line->wbs_task_id;

            if ($taskId !== null && ! in_array($taskId, $taskIds, true)) {
                // Tagged to a package of ANOTHER project — legacy poison the
                // Inventory guards now refuse at entry. It cannot honestly sit
                // in any of this project's packages, so it joins the
                // unattributed bucket and the report says why.
                $foreignIssueCodes[$line->issue_code] = true;
                $taskId = null;
            }

            if ($taskId === null) {
                $unattributedValue += (float) $line->amount;
                $unattributedCount++;

                if (count($unattributedRows) < self::UNATTRIBUTED_PAGE) {
                    $unattributedRows[] = [
                        'issue_id' => (int) $line->issue_id,
                        'issue_code' => $line->issue_code,
                        'issue_date' => substr((string) $line->issue_date, 0, 10),
                        'item_code' => $line->item_code,
                        'item_name' => $line->item_name,
                        'qty' => round((float) $line->qty, 4),
                        'unit' => $line->item_unit,
                        'amount' => round((float) $line->amount, 2),
                    ];
                }

                continue;
            }

            $key = $taskId.':'.(int) $line->item_id;
            $entry = $actual[$key] ?? [
                'item_id' => (int) $line->item_id,
                'item_code' => $line->item_code,
                'item_name' => $line->item_name,
                'actual_qty' => 0.0,
                'actual_unit' => $line->item_unit,
                'actual_value' => 0.0,
            ];
            $entry['actual_qty'] += (float) $line->qty;
            $entry['actual_value'] += (float) $line->amount;
            $actual[$key] = $entry;
        }

        if ($foreignIssueCodes !== []) {
            $warnings[] = sprintf(
                'Bon %s memuat baris yang ditandai ke paket pekerjaan proyek LAIN; baris itu dihitung '
                .'sebagai belum ditandai dan penandaannya perlu diperbaiki di bonnya.',
                implode(', ', array_keys($foreignIssueCodes)),
            );
        }

        return [
            $actual,
            [
                'rows' => $unattributedRows,
                'value' => round($unattributedValue, 2),
                'count' => $unattributedCount,
            ],
            round($issueTotal, 2),
        ];
    }

    // ----------------------------------------------------------------- merge

    /**
     * One row per (paket pekerjaan, item): theory and actual side by side, in
     * WBS order. Metadata for theory-only rows comes from inv_items when the
     * table exists, falling back to the AHSP component's own name.
     *
     * @param  Collection<int, WbsTask>  $tasks
     * @param  array<string, array>  $theoryByKey
     * @param  array<string, array>  $actualByKey
     * @param  array<int, string>  $noTheoryNote
     * @return list<array>
     */
    private function mergeRows(
        Collection $tasks,
        array $theoryByKey,
        array $actualByKey,
        array $noTheoryNote,
        array $thresholds,
    ): array {
        $items = $this->itemMaster($theoryByKey);
        $rows = [];

        foreach ($tasks as $task) {
            $prefix = $task->id.':';
            $keys = array_values(array_unique(array_merge(
                array_filter(array_keys($theoryByKey), fn (string $key): bool => str_starts_with($key, $prefix)),
                array_filter(array_keys($actualByKey), fn (string $key): bool => str_starts_with($key, $prefix)),
            )));

            // Item code order inside a package, so the same material lands on
            // the same visual spot in every package's block.
            usort($keys, function (string $a, string $b) use ($theoryByKey, $actualByKey, $items): int {
                $codeOf = fn (string $key): string => (string) ($actualByKey[$key]['item_code']
                    ?? $items[$theoryByKey[$key]['item_id'] ?? 0]['code']
                    ?? '');

                return $codeOf($a) <=> $codeOf($b);
            });

            foreach ($keys as $key) {
                $rows[] = $this->row(
                    $task,
                    $theoryByKey[$key] ?? null,
                    $actualByKey[$key] ?? null,
                    $noTheoryNote[$task->id] ?? 'tanpa_teori',
                    $items,
                    $thresholds,
                );
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array{code: ?string, name: ?string, unit: ?string}>  $items
     */
    private function row(
        WbsTask $task,
        ?array $theory,
        ?array $actual,
        string $taskNote,
        array $items,
        array $thresholds,
    ): array {
        $master = $items[$theory['item_id'] ?? $actual['item_id'] ?? 0] ?? null;

        $theoryQty = $theory === null ? null : round($theory['theory_qty'], 4);
        $theoryValue = $theory === null ? null : round($theory['theory_value'], 2);
        $theoryUnit = $theory['theory_unit'] ?? null;

        $actualQty = $actual === null ? 0.0 : round($actual['actual_qty'], 4);
        $actualValue = $actual === null ? 0.0 : round($actual['actual_value'], 2);
        // The unit is the ITEM MASTER's, known even before any usage exists:
        // the demo's B.3 row must read kg-vs-btg (variance_qty "—") from day
        // one, not flip units the moment the first bon arrives.
        $actualUnit = $actual['actual_unit'] ?? $master['unit'] ?? null;

        if ($theory === null) {
            // Usage on a package/item pairing the RAB never priced. The rupiah
            // stays in the selisih column — an overrun against zero theory is
            // real money — and the note says why no quantity can be compared.
            $note = $taskNote;
            $varianceQty = null;
            $varianceValue = $actualValue;
        } elseif ($theoryUnit !== null && $actualUnit !== null && $theoryUnit !== $actualUnit) {
            // kg vs btg: a quantity difference in mixed units is more dangerous
            // than no number at all. Rupiah still adds up.
            $note = 'satuan_berbeda';
            $varianceQty = null;
            $varianceValue = round($actualValue - $theoryValue, 2);
        } else {
            $note = null;
            $varianceQty = round($actualQty - $theoryQty, 4);
            $varianceValue = round($actualValue - $theoryValue, 2);
        }

        return [
            'wbs_task_id' => $task->id,
            'wbs_code' => $task->wbs_code,
            'wbs_name' => $task->name,
            'progress_pct' => round((float) $task->progress_pct, 4),
            'item_id' => $theory['item_id'] ?? $actual['item_id'] ?? null,
            'item_code' => $actual['item_code'] ?? $master['code'] ?? null,
            'item_name' => $actual['item_name'] ?? $master['name'] ?? $theory['component_name'] ?? null,
            'theory_qty' => $theoryQty,
            'theory_unit' => $theoryUnit,
            'theory_value' => $theoryValue,
            'actual_qty' => $actualQty,
            'actual_unit' => $actualUnit,
            'actual_value' => $actualValue,
            'variance_qty' => $varianceQty,
            'variance_value' => $varianceValue,
            'flagged' => $this->flagged($theoryValue, $actualQty, $actualValue, $varianceValue, $thresholds),
            'note' => $note,
        ];
    }

    /**
     * The threshold rule the screen prints verbatim from `thresholds`: flagged
     * when the variance exceeds pct% of theory AND the rupiah floor, or when
     * the variance alone reaches always_show_value whatever the percentage.
     *
     * A row with NO recorded usage is never flagged: with the demo's bucket at
     * 100% every theory row would otherwise scream "under theory", and zero
     * actual there means "not tagged yet", not "thrifty". The screen shows
     * those as "Belum ada pemakaian".
     */
    private function flagged(
        ?float $theoryValue,
        float $actualQty,
        float $actualValue,
        ?float $varianceValue,
        array $thresholds,
    ): bool {
        if ($varianceValue === null || ($actualValue <= 0 && $actualQty <= 0)) {
            return false;
        }

        $abs = abs($varianceValue);

        if ($abs >= (float) $thresholds['always_show_value']) {
            return true;
        }

        // Against zero theory any positive variance is infinitely many
        // percent, so only the rupiah floor is left to decide.
        $pctExceeded = ($theoryValue === null || $theoryValue <= 0)
            ? $abs > 0
            : $abs > $theoryValue * (float) $thresholds['pct'] / 100;

        return $pctExceeded && $abs > (float) $thresholds['value'];
    }

    /**
     * Item master metadata for theory-only rows (actual rows carry their own
     * from the join). Reads inv_items only when it exists — on an install
     * without Inventory the AHSP component name is all there is, and that is
     * what gets shown.
     *
     * @param  array<string, array>  $theoryByKey
     * @return array<int, array{code: ?string, name: ?string, unit: ?string}>
     */
    private function itemMaster(array $theoryByKey): array
    {
        $ids = array_values(array_unique(array_map(
            fn (array $entry): int => (int) $entry['item_id'],
            $theoryByKey,
        )));

        if ($ids === [] || ! Schema::hasTable('inv_items')) {
            return [];
        }

        return DB::table('inv_items')
            ->whereIn('id', $ids)
            ->get(['id', 'code', 'name', 'unit'])
            ->keyBy('id')
            ->map(fn (object $item): array => [
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->unit,
            ])
            ->all();
    }

    /**
     * NOTHING in the payload is ever INF or NAN — json_encode() throws on
     * both, and a failed encode is an HTTP 500 on the one report somebody
     * needed. Same guard as EvmService::finite.
     */
    private function finite(float $value, int $decimals = 4): ?float
    {
        return is_finite($value) ? round($value, $decimals) : null;
    }
}
