<?php

namespace Modules\Projects\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\Core\Enums\DocumentStatus;
use Modules\Projects\Enums\DailyReportRole;
use Modules\Projects\Models\BaselineTask;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\DailyReportActivity;
use Modules\Projects\Models\DailyReportEquipment;
use Modules\Projects\Models\DailyReportManpower;
use Modules\Projects\Models\DailyReportReceipt;
use Modules\Projects\Models\ProgressMeasurement;
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
 *   - The pad's twelve JUMLAH ORANG rows fill ONLY from
 *     prj_daily_report_manpower, one row per DailyReportRole. A role with no
 *     row comes back null and the pad's rule prints — never a share of
 *     manpower_count, which stays the TOTAL line and nothing more. Reports
 *     from before P0-A have no rows at all and print exactly as they always
 *     did: twelve blanks and one number.
 *   - prj_daily_report_materials is qty_USED. The pad's "jumlah yang
 *     diterima" / "jumlah yang ditolak" pair is an arrival fact and fills
 *     only from prj_daily_report_receipts; consumption keeps printing under
 *     its own heading, because printing the one under the other's heading is
 *     the same lie as inventing the figure.
 *   - prj_weekly_progress is the only source for the RENCANA/REALISASI footer,
 *     and a week with no row comes back null rather than 0.0. "Rencana 0%,
 *     realisasi 0%" on a signed schedule sheet is a statement that the site
 *     stopped, and a missing row says nothing of the kind.
 *   - REALISASI is one of TWO different numbers since P3 and the sheet says
 *     which: a percentage a supervisor typed, or the value-weighted figure an
 *     APPROVED OPNAME produced (prj_weekly_progress.actual_pct_source). An
 *     estimate and a measurement printed identically, under one footnote
 *     asserting a single provenance, is the plausible-looking cell PANDUAN
 *     §13.5 forbids — so the measured weeks carry their opname number under
 *     the figure and the footnote describes only the sources actually printed.
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
    // The manpower table exactly as the owner's pad has it — twelve roles,
    // then TOTAL — no longer lives here as a constant: DailyReportRole is the
    // one source both this printer and the store/update validation consume,
    // so a row cannot fill itself on the sheet without its role_key having
    // passed through the same list on the way into the database.

    /** Senin..Sabtu. The pad has six day columns; Minggu is not one of them. */
    private const DAY_LETTERS = ['S', 'S', 'R', 'K', 'J', 'S'];

    /** A month overlaps at most six ISO weeks (31 days beginning on Sunday). */
    private const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII'];

    /**
     * How many ruled rows each pad table gets when it has nothing to print.
     *
     * Chosen so the whole sheet still lands on one A4 portrait page next to a
     * thirteen-row manpower table, a notes block and three signature columns.
     * Fewer rows than the pad has, and deliberately: a second page of nothing
     * but blanks is a page the site throws away.
     *
     * Sourced rows displace blanks one for one (FormPrintService's registry
     * minRows pattern): the table keeps this geometry until the data outgrows
     * it, and a report with no rows prints all-blank exactly as before P0-A.
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
        $report->loadMissing(['project', 'materials.item', 'manpower', 'equipment', 'receipts', 'activityLines']);

        $materialMasuk = $this->materialMasuk($report);
        $alat = $this->alat($report);
        $uraianRows = $this->uraianRows($report);

        return [
            'manpower' => $this->manpowerRows($report),
            'materialsUsed' => $this->materialsUsed($report),
            'materialMasuk' => $materialMasuk,
            'alat' => $alat,
            'uraianRows' => $uraianRows,
            'workHours' => [
                'start' => $this->clock($report->work_start),
                'end' => $this->clock($report->work_end),
                'reason' => $this->text($report->lost_hours_reason),
            ],
            'weather' => [
                'pagi' => $report->weather_am?->label(),
                'sore' => $report->weather_pm?->label(),
            ],
            'activities' => $this->text($report->activities),
            'obstacles' => $this->text($report->obstacles),
            'safetyNotes' => $this->text($report->safety_notes),
            'blankRows' => [
                'materialMasuk' => max(0, self::BLANK_ROWS['materialMasuk'] - count($materialMasuk)),
                'alat' => max(0, self::BLANK_ROWS['alat'] - count($alat)),
                // The legacy layout is one summary row plus these blanks; the
                // sourced layout pads to the same four-row table.
                'uraian' => $uraianRows === []
                    ? self::BLANK_ROWS['uraian']
                    : max(0, self::BLANK_ROWS['uraian'] + 1 - count($uraianRows)),
            ],
            'handFilled' => $this->handFilled($report),
        ];
    }

    /**
     * The footnote names only the cells still manual for THIS report.
     *
     * Each sentence stands while its table has no rows — for a pre-P0-A
     * report that is all four, word for word as the sheet has always printed
     * them, and for that report each is still the truth: its per-jabatan
     * numbers, receipts, equipment and daily progress were never recorded
     * anywhere and live only on the paper. The moment a table has rows it is
     * sourced, its sentence goes, and a report sourced everywhere prints no
     * footnote at all.
     *
     * @return list<string>
     */
    private function handFilled(DailyReport $report): array
    {
        return array_values(array_filter([
            $report->manpower->isEmpty()
                ? 'Kolom JUMLAH ORANG per jabatan diisi manual — ERP hanya menyimpan satu angka tenaga kerja '
                    .'untuk seluruh proyek per hari, bukan rinciannya per jabatan.'
                : null,
            $report->receipts->isEmpty()
                ? 'Tabel MATERIAL YANG MASUK HARI INI diisi manual — penerimaan barang tercatat per surat jalan '
                    .'di modul Pengadaan, bukan per laporan harian, dan jumlah yang DITOLAK tidak tercatat di mana pun.'
                : null,
            $report->equipment->isEmpty()
                ? 'Tabel ALAT-ALAT diisi manual — ERP tidak menyimpan pemakaian alat per hari.'
                : null,
            $report->activityLines->isEmpty()
                ? 'Kolom PROGRESS dan TARGET diisi manual — progres dicatat per paket pekerjaan WBS dan per minggu, '
                    .'tidak pernah per hari.'
                : null,
        ]));
    }

    /**
     * Twelve rows in the pad's own order — DailyReportRole::cases() — each
     * filled ONLY when prj_daily_report_manpower holds a row for that
     * role_key, and a ruled blank otherwise. A report from before the line
     * table existed has no rows and prints as it always did.
     *
     * TOTAL stays manpower_count: for a report with rows the service derives
     * it from their sum, and for an old report it is the one number the site
     * ever recorded. manpower_count is unsigned with a default of 0, so "0"
     * is what an untouched field looks like as much as it is what an empty
     * site looks like — on a sheet three parties sign, the two must not print
     * the same, so a zero total is a blank as well.
     *
     * @return list<array{label: string, count: ?int, total: bool}>
     */
    private function manpowerRows(DailyReport $report): array
    {
        $rows = [];

        foreach (DailyReportRole::cases() as $role) {
            // Identity match against the cast enum: the row order the relation
            // hands back is entry order, and the pad's order is the enum's.
            $line = $report->manpower->first(
                fn (DailyReportManpower $row): bool => $row->role_key === $role,
            );

            $rows[] = ['label' => $role->label(), 'count' => $line?->headcount, 'total' => false];
        }

        $total = (int) $report->manpower_count;
        $rows[] = ['label' => 'TOTAL', 'count' => $total > 0 ? $total : null, 'total' => true];

        return $rows;
    }

    /**
     * MATERIAL MASUK — arrival rows from prj_daily_report_receipts, the table
     * that answers the pad's diterima/ditolak column pair.
     *
     * rejected prints even at 0: unlike manpower_count's untouched default,
     * a receipt row exists only because somebody recorded that delivery, so
     * "nothing rejected" is a recorded statement rather than an absence.
     *
     * @return list<array{description: string, received: float, rejected: float, unit: ?string, reason: ?string}>
     */
    private function materialMasuk(DailyReport $report): array
    {
        return $report->receipts
            ->map(fn (DailyReportReceipt $row): array => [
                'description' => $row->description,
                'received' => (float) $row->qty_received,
                'rejected' => (float) $row->qty_rejected,
                'unit' => $this->text($row->unit),
                'reason' => $this->text($row->rejection_reason),
            ])
            ->values()
            ->all();
    }

    /**
     * ALAT-ALAT from prj_daily_report_equipment. hours is null when nobody
     * recorded it — the sheet prints nothing, not "0 jam".
     *
     * @return list<array{description: string, qty: int, hours: ?float}>
     */
    private function alat(DailyReport $report): array
    {
        return $report->equipment
            ->map(fn (DailyReportEquipment $row): array => [
                'description' => $row->description,
                'qty' => (int) $row->qty,
                'hours' => $row->hours === null ? null : (float) $row->hours,
            ])
            ->values()
            ->all();
    }

    /**
     * URAIAN / PROGRESS / TARGET / HAMBATAN from prj_daily_report_activities,
     * in sort_order (the relation orders them; entry order breaks ties). A
     * null note keeps its ruled blank inside an otherwise sourced row —
     * per-line progress the site did not record is still not the ERP's to
     * invent.
     *
     * @return list<array{description: ?string, progress: ?string, target: ?string, obstacle: ?string}>
     */
    private function uraianRows(DailyReport $report): array
    {
        return $report->activityLines
            ->map(fn (DailyReportActivity $row): array => [
                'description' => $this->text($row->description),
                'progress' => $this->text($row->progress_note),
                'target' => $this->text($row->target_note),
                'obstacle' => $this->text($row->obstacle),
            ])
            ->values()
            ->all();
    }

    /**
     * work_start/work_end as the pad line prints them: HH:MM.
     *
     * The columns are TIME and the model deliberately leaves them un-cast, so
     * most drivers hand back the stored 'HH:MM' — but one that appends
     * seconds must not put ':00' on the signed sheet.
     */
    private function clock(?string $value): ?string
    {
        $text = $this->text($value);

        return $text === null ? null : substr($text, 0, 5);
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
                'Baris JUMLAH BOBOT RENCANA adalah persentase RENCANA kumulatif proyek dari progres mingguan; '
                    .'minggu yang belum punya baris progres dicetak kosong, bukan 0%.',
                $this->realisasiProvenance($weeks),
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
     * that covers each one — AND, for every week, where its realisasi came
     * from.
     *
     * actualSource is the row's own label (WeeklyProgress::SOURCE_*), null for
     * a week with no row at all. actualOpname is the opname whose approval put
     * the figure there, and actualNote is what the sheet prints under the
     * number — the opname's code, or the word alone when the document behind an
     * opname-sourced week can no longer be found. A typed percentage carries
     * neither, so an unmarked column and a marked one cannot be read as the
     * same kind of statement.
     *
     * @return list<array<string, mixed>>
     */
    private function weeks(Project $project, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $weeks = [];
        $cursor = $monthStart->startOfWeek(CarbonInterface::MONDAY);
        $index = 0;
        $opnames = null; // loaded once, and only if a week actually needs it

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

            // Only a row that actually prints a figure gets a provenance: a
            // week nobody reported says nothing about where its number came
            // from, because it has no number.
            $actual = $progress?->actual_pct === null ? null : (float) $progress->actual_pct;
            $source = $actual === null
                ? null
                // The column is NOT NULL with a default, but a row written
                // before the P3 migration (or by a raw insert) can still hand
                // back null, and every such percentage genuinely was typed.
                : (string) ($progress->actual_pct_source ?? WeeklyProgress::SOURCE_WEEKLY);

            $opname = null;

            if ($source === WeeklyProgress::SOURCE_MEASUREMENT) {
                $opnames ??= $this->approvedOpnames($project);
                $opname = $this->opnameAsAt($opnames, $progress?->period_end?->toDateString());
            }

            $weeks[] = [
                'roman' => self::ROMAN[$index] ?? (string) ($index + 1),
                'start' => $cursor->toDateString(),
                'end' => $saturday->toDateString(),
                // Numeric, not "23 Februari": the block is 25mm wide.
                'label' => $cursor->format('d/m').' – '.$saturday->format('d/m'),
                'days' => $days,
                'planned' => $progress?->planned_pct === null ? null : (float) $progress->planned_pct,
                'actual' => $actual,
                'actualSource' => $source,
                'actualOpname' => $opname,
                'actualNote' => $source === WeeklyProgress::SOURCE_MEASUREMENT
                    ? ($opname ?? 'dari opname disetujui')
                    : null,
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
     * Every APPROVED opname of this project's contract, oldest first, as
     * period_end => code.
     *
     * Keyed on the CONTRACT and filtered on the same two statuses
     * MeasurementService::actualPctAt sums over, because the number printed in
     * the REALISASI row is that sum: naming a document from a different set
     * would name a document that did not produce the figure. A project with no
     * contract cannot have an opname-sourced week at all, and gets no query.
     *
     * One query per sheet, loaded lazily and only when a week actually says
     * SOURCE_MEASUREMENT — the same rule the rest of this read model keeps.
     *
     * @return list<array{end: string, code: string}>
     */
    private function approvedOpnames(Project $project): array
    {
        if ($project->contract_id === null) {
            return [];
        }

        return ProgressMeasurement::query()
            ->where('contract_id', $project->contract_id)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->orderBy('period_end')
            ->orderBy('id')
            ->get(['code', 'period_end'])
            ->map(fn (ProgressMeasurement $row): array => [
                'end' => (string) $row->period_end?->toDateString(),
                'code' => (string) $row->code,
            ])
            ->all();
    }

    /**
     * The LAST opname closing on or before this week's period_end — the one
     * whose approval last moved the figure.
     *
     * The percentage itself is cumulative over every approved opname up to that
     * date, which is why the footnote says so rather than letting one code be
     * read as the whole story. Null when no opname reaches back that far, which
     * on a row labelled SOURCE_MEASUREMENT means the document was deleted after
     * it wrote the number: the sheet then prints the word without a number it
     * cannot support.
     *
     * @param  list<array{end: string, code: string}>  $opnames
     */
    private function opnameAsAt(array $opnames, ?string $asOf): ?string
    {
        if ($asOf === null) {
            return null;
        }

        $code = null;

        // String comparison on 'Y-m-d' — exact and total, the rule bars() uses.
        foreach ($opnames as $opname) {
            if ($opname['end'] !== '' && $opname['end'] <= $asOf) {
                $code = $opname['code'];
            }
        }

        return $code;
    }

    /**
     * The footnote sentence for the REALISASI row: it describes the sources the
     * sheet ACTUALLY printed, and there are three different true sentences.
     *
     * A month whose weeks are all typed may not mention opname, a month whose
     * weeks all came from opname may not call them progres mingguan, and a
     * mixed month — which is the ordinary case while the first opname is being
     * signed — has to say both and say which is which. A month that prints no
     * realisasi at all gets no sentence: there is no number whose provenance
     * could be stated.
     *
     * @param  list<array<string, mixed>>  $weeks
     */
    private function realisasiProvenance(array $weeks): ?string
    {
        $sources = array_column(array_filter($weeks, fn (array $week): bool => $week['actual'] !== null), 'actualSource');

        $measured = in_array(WeeklyProgress::SOURCE_MEASUREMENT, $sources, true);
        $typed = in_array(WeeklyProgress::SOURCE_WEEKLY, $sources, true);

        if ($measured && $typed) {
            return 'Baris JUMLAH BOBOT REALISASI bercampur sumber: minggu yang mencantumkan nomor OPN diambil dari '
                .'OPNAME yang telah DISETUJUI — persentase berbobot NILAI atas BOQ kontrak, kumulatif sampai opname '
                .'tersebut — sedangkan minggu tanpa nomor memakai persen yang DIKETIK pada progres mingguan '
                .'(taksiran pengawas, bukan hasil pengukuran).';
        }

        if ($measured) {
            return 'Baris JUMLAH BOBOT REALISASI diambil dari OPNAME yang telah DISETUJUI, bukan dari persen yang '
                .'diketik pada progres mingguan: persentase berbobot NILAI atas BOQ kontrak. Nomor di bawah angkanya '
                .'adalah opname terakhir yang tercakup; angkanya kumulatif atas seluruh opname disetujui sampai minggu itu.';
        }

        if ($typed) {
            // Deliberately silent about opname: no figure on this sheet came
            // from one, and naming a document the sheet does not print is how a
            // footnote starts implying data that is not there.
            return 'Baris JUMLAH BOBOT REALISASI adalah persentase realisasi kumulatif yang DIKETIK pada progres '
                .'mingguan — taksiran pengawas, bukan hasil pengukuran volume di lapangan.';
        }

        return null;
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
