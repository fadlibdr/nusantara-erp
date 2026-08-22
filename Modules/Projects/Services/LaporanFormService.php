<?php

namespace Modules\Projects\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\Projects\Models\BaselineTask;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;
use Modules\Projects\Models\WeeklyProgress;

/**
 * The body of the two forms the site files every day and every week.
 *
 * Everything above and below the body table — the four-party band, the identity
 * block, the signatures — is assembled once by
 * Modules\Core\Services\FormPrintService::header(). This class answers only the
 * question that is specific to Projects: what goes in the grid.
 *
 * IT IS A READ MODEL AND NOTHING ELSE. No query it runs writes, and no number
 * it returns is computed from a number it invented. That second half is the
 * whole reason it exists as a class rather than as logic in a Blade file,
 * because the interesting property of these two forms is what they must REFUSE
 * to print:
 *
 *   - prj_daily_reports holds ONE headcount for the whole site
 *     (manpower_count). The pad wants twelve, by role. Eleven of those twelve
 *     cannot be derived from one, and the twelfth is not the total's identity
 *     either — so all twelve come back null and the pad's dotted rule prints.
 *   - prj_daily_report_materials is qty_USED. The pad's columns are "jumlah
 *     yang diterima" and "jumlah yang ditolak", which are goods-receipt facts
 *     (and the rejected quantity is recorded absolutely nowhere). Consumption
 *     is therefore printed under its own heading and the receipt table stays
 *     empty, because printing the one under the other's heading is the same
 *     lie as inventing the figure.
 *   - prj_weekly_progress is the only source for the RENCANA/REALISASI footer,
 *     and a week with no row comes back null rather than 0.0. "Rencana 0%,
 *     realisasi 0%" on a signed schedule sheet is a statement that the site
 *     stopped, and a missing row says nothing of the kind.
 *   - The bar in the day grid is prj_baseline_tasks' frozen span and nothing
 *     else. prj_wbs_tasks carries planned_start/planned_end too, but those move
 *     — a bar drawn from them redraws itself every time the plan slips, which is
 *     the opposite of what a schedule sheet is for. No approved baseline, no
 *     bar, and the sheet says so out loud.
 *
 * Nothing here formats a date or a number. The templates get the raw values and
 * FormPrintService's own $date/$money closures, so a form cannot drift from the
 * four dompdf documents in how it writes 25 Maret 2026.
 */
class LaporanFormService
{
    /**
     * The manpower table exactly as the owner's pad has it: twelve roles, then
     * TOTAL. Kept here rather than in the template because the honesty rule is
     * about this list — every one of these rows is a ruled blank, and a future
     * edit that quietly fills one has to come through this file.
     */
    private const MANPOWER_ROLES = [
        'Project Manager',
        'Deputy Project Manager',
        'Engineering',
        'Komersial',
        'Keuangan',
        'Danlat',
        'Produksi',
        'Safety Officer',
        'Mandor Sipil + Tukang',
        'Mandor Arsitek + Tukang',
        'Mandor MEP + Tukang',
        'Subkont',
    ];

    /** Senin..Sabtu. The pad has six day columns; Minggu is not one of them. */
    private const DAY_LETTERS = ['S', 'S', 'R', 'K', 'J', 'S'];

    /** A month overlaps at most six ISO weeks (31 days beginning on Sunday). */
    private const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII'];

    /**
     * How many empty ruled rows each unsourced table gets.
     *
     * Chosen so the whole sheet still lands on one A4 portrait page next to a
     * thirteen-row manpower table, a notes block and three signature columns.
     * Fewer rows than the pad has, and deliberately: a second page of nothing
     * but blanks is a page the site throws away.
     */
    private const BLANK_ROWS = ['materialMasuk' => 5, 'alat' => 4, 'uraian' => 3];

    public function __construct(private readonly BaselineService $baselines) {}

    // ---------------------------------------------------------------- harian

    /**
     * LAPORAN HARIAN — one report, one printed page.
     *
     * @return array<string, mixed>
     */
    public function harian(DailyReport $report): array
    {
        $report->loadMissing(['project', 'materials.item']);

        return [
            'manpower' => $this->manpowerRows($report),
            'materialsUsed' => $this->materialsUsed($report),
            'weather' => [
                'pagi' => $report->weather_am?->label(),
                'sore' => $report->weather_pm?->label(),
            ],
            'activities' => $this->text($report->activities),
            'obstacles' => $this->text($report->obstacles),
            'safetyNotes' => $this->text($report->safety_notes),
            'blankRows' => self::BLANK_ROWS,
            'handFilled' => [
                'Kolom JUMLAH ORANG per jabatan diisi manual — ERP hanya menyimpan satu angka tenaga kerja '
                    .'untuk seluruh proyek per hari, bukan rinciannya per jabatan.',
                'Tabel MATERIAL YANG MASUK HARI INI diisi manual — penerimaan barang tercatat per surat jalan '
                    .'di modul Pengadaan, bukan per laporan harian, dan jumlah yang DITOLAK tidak tercatat di mana pun.',
                'Tabel ALAT-ALAT diisi manual — ERP tidak menyimpan pemakaian alat per hari.',
                'Kolom PROGRESS dan TARGET diisi manual — progres dicatat per paket pekerjaan WBS dan per minggu, '
                    .'tidak pernah per hari.',
            ],
        ];
    }

    /**
     * Twelve ruled blanks and one number.
     *
     * manpower_count is unsigned with a default of 0, so "0" is what an
     * untouched field looks like as much as it is what an empty site looks
     * like. On a sheet three parties sign, the two must not print the same, so
     * a zero total is a blank as well.
     *
     * @return list<array{label: string, count: ?int, total: bool}>
     */
    private function manpowerRows(DailyReport $report): array
    {
        $rows = [];

        foreach (self::MANPOWER_ROLES as $role) {
            $rows[] = ['label' => $role, 'count' => null, 'total' => false];
        }

        $total = (int) $report->manpower_count;
        $rows[] = ['label' => 'TOTAL', 'count' => $total > 0 ? $total : null, 'total' => true];

        return $rows;
    }

    /**
     * Material CONSUMED on site that day — labelled as such by the template.
     *
     * @return list<array{name: string, code: ?string, qty: float, unit: ?string}>
     */
    private function materialsUsed(DailyReport $report): array
    {
        return $report->materials
            ->map(fn ($line): array => [
                // A line whose item has since been deleted still happened. The
                // id is printed rather than a blank so the storeman can trace it.
                'name' => $line->item?->name ?? ('Material #'.$line->item_id),
                'code' => $line->item?->code,
                'qty' => (float) $line->qty_used,
                'unit' => $this->text($line->unit) ?? $line->item?->unit,
            ])
            ->values()
            ->all();
    }

    // -------------------------------------------------------------- mingguan

    /**
     * LAPORAN MINGGUAN — the landscape DETAIL SCHEDULE / PROGRAM KERJA.
     *
     * One column block per ISO week overlapping the month, six day columns each
     * (Senin..Sabtu). Four blocks is the usual printing and what the pad has
     * pre-drawn, but a 31-day month beginning on a Sunday genuinely touches six
     * of them, and dropping the fifth and sixth would silently hide the end of
     * the month rather than print it.
     *
     * @return array<string, mixed>
     */
    public function mingguan(Project $project, int $year, int $month): array
    {
        $monthStart = CarbonImmutable::createFromDate($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();

        $weeks = $this->weeks($project, $monthStart, $monthEnd);
        $dates = [];

        foreach ($weeks as $week) {
            foreach ($week['days'] as $day) {
                $dates[] = $day['date'];
            }
        }

        $baseline = $this->baselines->currentFor($project);
        $spans = $baseline === null
            ? new Collection
            : $baseline->tasks()->get()->keyBy('wbs_code');

        $rows = $this->scheduleRows($project, $dates, $spans, $baseline !== null);
        $leafWeights = array_column(array_filter($rows, fn (array $row): bool => ! $row['group']), 'weight');

        return [
            'weeks' => $weeks,
            'rows' => $rows,
            'weightTotal' => $leafWeights === [] ? null : round(array_sum($leafWeights), 4),
            'baseline' => $baseline,
            'shaded' => $baseline !== null,
            'periodStart' => $monthStart->toDateString(),
            'periodEnd' => $monthEnd->toDateString(),
            'handFilled' => array_values(array_filter([
                'Kolom VOLUME memuat volume KONTRAK dari BOQ yang tertaut, bukan volume bulan berjalan — '
                    .'ERP tidak menyimpan pemecahan volume per bulan; kosong berarti paket itu belum tertaut ke baris BOQ.',
                'Baris JUMLAH BOBOT RENCANA dan REALISASI adalah persentase KUMULATIF proyek dari progres mingguan; '
                    .'minggu yang belum punya baris progres dicetak kosong, bukan 0%.',
                $baseline === null
                    ? 'Batang rencana TIDAK dicetak: proyek ini belum memiliki baseline yang disetujui, '
                        .'sehingga kolom hari diisi manual seperti pada form kertas.'
                    : 'Batang rencana diarsir dari baseline '.$baseline->code.' (rencana yang dibekukan dan tidak dapat diubah), '
                        .'bukan dari realisasi di lapangan.',
            ])),
        ];
    }

    /**
     * The ISO weeks that overlap the month, with the cumulative progress row
     * that covers each one.
     *
     * @return list<array<string, mixed>>
     */
    private function weeks(Project $project, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $weeks = [];
        $cursor = $monthStart->startOfWeek(CarbonInterface::MONDAY);
        $index = 0;

        while ($cursor->lessThanOrEqualTo($monthEnd)) {
            $days = [];

            foreach (self::DAY_LETTERS as $offset => $letter) {
                $day = $cursor->addDays($offset);
                $days[] = [
                    'letter' => $letter,
                    'date' => $day->toDateString(),
                    'dom' => $day->format('j'),
                    // 23-28 appears twice in a month that opens on a Sunday, so
                    // the template greys the days that belong to the neighbour.
                    'inMonth' => $day->month === $monthStart->month && $day->year === $monthStart->year,
                ];
            }

            $saturday = $cursor->addDays(5);
            $progress = $this->weekProgress($project, $cursor);

            $weeks[] = [
                'roman' => self::ROMAN[$index] ?? (string) ($index + 1),
                'start' => $cursor->toDateString(),
                'end' => $saturday->toDateString(),
                // Numeric, not "23 Februari": the block is 25mm wide.
                'label' => $cursor->format('d/m').' – '.$saturday->format('d/m'),
                'days' => $days,
                'planned' => $progress?->planned_pct === null ? null : (float) $progress->planned_pct,
                'actual' => $progress?->actual_pct === null ? null : (float) $progress->actual_pct,
            ];

            $index++;
            $cursor = $cursor->addWeek();
        }

        return $weeks;
    }

    /**
     * The weekly progress row covering this week's Monday.
     *
     * Matched on the DATE RANGE, never on week_no. prj_weekly_progress.week_no
     * is whatever the person entering it typed, and the day grid is built from
     * the calendar — joining the two on a number nobody validates is how a
     * column ends up labelled minggu 7 while carrying minggu 8's percentages.
     *
     * whereDate rather than a plain where, and that is not cosmetic: the `date`
     * cast stores '2026-02-23 00:00:00', so `period_start <= '2026-02-23'` is a
     * string comparison that answers false for the very row it should match.
     */
    private function weekProgress(Project $project, CarbonImmutable $monday): ?WeeklyProgress
    {
        return WeeklyProgress::query()
            ->where('project_id', $project->id)
            ->whereDate('period_start', '<=', $monday->toDateString())
            ->whereDate('period_end', '>=', $monday->toDateString())
            ->orderBy('period_start')
            ->first();
    }

    /**
     * The WBS as the pad wants it: parents as section headings carrying their
     * rolled-up bobot, leaves numbered 1..n underneath.
     *
     * @param  Collection<string, BaselineTask>  $spans
     * @param  list<string>  $dates
     * @return list<array<string, mixed>>
     */
    private function scheduleRows(Project $project, array $dates, Collection $spans, bool $hasBaseline): array
    {
        $tasks = $project->wbsTasks()->with('boqItem')->get();
        $children = $tasks->groupBy(fn (WbsTask $task): int => (int) $task->parent_id);

        $rows = [];
        $number = 0;

        $walk = function (int $parentId, int $depth) use (&$walk, &$rows, &$number, $children, $spans, $dates, $hasBaseline): void {
            $branch = $children[$parentId] ?? new Collection;

            foreach ($branch->sortBy([['sort_order', 'asc'], ['wbs_code', 'asc']]) as $task) {
                $isGroup = ($children[(int) $task->id] ?? new Collection)->isNotEmpty();
                $span = $spans[$task->wbs_code] ?? null;

                $rows[] = [
                    'no' => $isGroup ? null : ++$number,
                    'group' => $isGroup,
                    'depth' => $depth,
                    'code' => $task->wbs_code,
                    'name' => $task->name,
                    // Contract volume from the linked BOQ line, or nothing. The
                    // pad's column says "bulan ini" and no such split exists;
                    // the footnote on the sheet says so in as many words.
                    'volume' => $task->boqItem === null ? null : (float) $task->boqItem->qty,
                    'unit' => $task->boqItem?->unit,
                    'weight' => (float) $task->weight_pct,
                    'bars' => $this->bars($dates, $span),
                    // A package added after the plan was frozen. Its bar is
                    // absent for a reason worth naming on the sheet.
                    'offBaseline' => $hasBaseline && $span === null,
                ];

                $walk((int) $task->id, $depth + 1);
            }
        };

        $walk(0, 0);

        return $rows;
    }

    /**
     * One boolean per day column: is this day inside the frozen planned span.
     *
     * String comparison on 'Y-m-d' is exact and total, which a Carbon between()
     * on values the `date` cast may hand back at midnight is not.
     *
     * @param  list<string>  $dates
     * @return list<bool>
     */
    private function bars(array $dates, ?BaselineTask $span): array
    {
        $from = $span?->planned_start?->toDateString();
        $to = $span?->planned_end?->toDateString();

        if ($from === null || $to === null) {
            return array_fill(0, count($dates), false);
        }

        return array_map(fn (string $date): bool => $date >= $from && $date <= $to, $dates);
    }

    /** Empty string is nothing recorded, and nothing recorded is a ruled blank. */
    private function text($value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
