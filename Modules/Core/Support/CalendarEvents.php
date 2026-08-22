<?php

namespace Modules\Core\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every dated plan in the system, aggregated into one month.
 *
 * WatchedDeadlines answers "what is overdue or at risk"; this registry answers
 * "what happens WHEN". It therefore COMPOSES WatchedDeadlines instead of
 * copying it: the eighteen registry entries are reused whole — same tables,
 * same scope closures (via WatchedDeadlines::scoped()), same schema
 * degradation (via WatchedDeadlines::missingSchema()) — but windowed by
 * [from, to] instead of tiered by urgency. The scopes stay defined once and
 * pinned by DeadlineWatchTest; forking them here is how the calendar and the
 * watcher would drift into contradicting each other. On top of those come the
 * calendar-only sources: dates that are PLANS rather than obligations (hari
 * gajian, tutup buku, proyek mulai, kunjungan PM) — a deadline watcher has
 * nothing to say about them, a calendar is exactly where they belong.
 *
 * What is deliberately NOT here:
 *
 *  - TANPA_TANGGAL / undated logic. A calendar can only show dated things;
 *    the 13 unbilled termins with NULL due_date are tenggat's BLIND line to
 *    report, never this screen's to fake. On the demo data that blindness is
 *    real: Keuangan's termin plans stay invisible until the dates are entered.
 *  - Tiers or urgency flags. Duplicating MENIPIS/LEWAT would blur the sibling
 *    split the two screens are built on.
 *  - Span bars. A contract or project is emitted as TWO independent
 *    single-day events (mulai + berakhir/target selesai) — the grids render
 *    dots, and at demo volume span rendering is complexity without payoff.
 *
 * August 2026 on the live demo data: 4 events — PM CCTV Bulanan 5 Agu and PM
 * Akses Kontrol & Alarm Bulanan 12 Agu (Layanan, SVC/2026/III/0001),
 * QTN/2026/VII/0004 berlaku s/d 31 Agu (Penjualan, Rp 33,97 jt), and Tutup
 * buku Agustus 2026 on 31 Agu (Keuangan, period 2026-08 open).
 */
class CalendarEvents
{
    /**
     * Rows fetched per source per window. With ~23 sources this bounds a
     * pathological month at a few thousand rows before the controller's
     * MAX_EVENTS cap; on the demo data no source carries more than 2.
     */
    public const FETCH_LIMIT = 120;

    /**
     * Events a caller receives per month. Applied by CalendarController AFTER
     * permission filtering (so events a caller may see are never crowded out
     * by ones they may not) and disclosed via meta.capped / meta.total.
     */
    public const MAX_EVENTS = 500;

    /**
     * Table prefix → the owner's eight department chips. 'inv' has no dated
     * planning source in the schema today, so that chip simply never appears —
     * stated honestly rather than force-fed.
     */
    private const DEPARTMENTS = [
        'crm' => 'Penjualan',
        'prj' => 'Proyek',
        // The owner's eight-label list has no Subkontrak, and an SPK end is a
        // site event the PM plans around.
        'scm' => 'Proyek',
        'fin' => 'Keuangan',
        'hr' => 'SDM',
        'prc' => 'Pengadaan',
        'svc' => 'Layanan',
        'ast' => 'Aset',
        'inv' => 'Persediaan',
    ];

    /**
     * Two registry entries whose audience is finance even though their table
     * prefix is not fin_: a termin's "rencana tagih" and a BAST's retention
     * refund both link to FINANCE screens ('siap-tagih' / 'retensi') and carry
     * fin.create as their act permission in the registry. Deriving crm.view /
     * prj.view from the prefix would hide the billing plan from the very
     * finance viewers those screens serve, and file the events under the wrong
     * legend chip.
     */
    private const AUDIENCE_OVERRIDES = [
        'termin_due' => ['department' => 'Keuangan', 'permission' => 'fin.view'],
        'bast_retention_release' => ['department' => 'Keuangan', 'permission' => 'fin.view'],
    ];

    /**
     * Registry titles compose "label date_word" ('Penawaran berlaku s/d');
     * exactly one entry reads badly that way ("PKWT karyawan PKWT berakhir").
     */
    private const TITLE_OVERRIDES = [
        'pkwt_end' => 'PKWT berakhir',
    ];

    private const BULAN = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    /**
     * Every event dated inside [from, to], both ends inclusive, sorted by
     * (date, department, code). Pure data — permission filtering and the
     * MAX_EVENTS cap stay in CalendarController, exactly like
     * WatchedDeadlines::scan() / DeadlineController split the same work.
     *
     * Each event carries a 'permission' key ({module}.view) for the controller
     * to filter on and then strip; it is never part of the API response.
     *
     * @return array{checked: int, skipped: array<int, array{kind: string, table: string, reason: string}>, truncated: array<int, array{kind: string, table: string}>, events: array<int, array<string, mixed>>}
     */
    public static function window(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $checked = 0;
        $skipped = [];
        $truncated = [];
        $events = [];

        foreach (self::sources() as $source) {
            // The same degradation rule as the watcher, for every source
            // including the calendar-only ones: two teams migrate this
            // repository daily, and a half-migrated table must become a
            // skipped line, never a crash that kills the month render.
            $reason = WatchedDeadlines::missingSchema($source);

            if ($reason !== null) {
                $skipped[] = ['kind' => $source['kind'], 'table' => $source['table'], 'reason' => $reason];

                continue;
            }

            $checked++;

            $rows = isset($source['resolver'])
                ? ($source['resolver'])($source, $from, $to)
                : self::fetch($source, $from, $to);

            /* A source that filled its per-source limit has ALMOST CERTAINLY
               dropped its tail — the last days of the month simply never
               render, while tenggat (which counts the full scope) says
               otherwise. Silence here is how a finance user plans month-end
               around a calendar missing the heaviest collection days. */
            if (count($rows) >= self::FETCH_LIMIT) {
                $truncated[] = ['kind' => $source['kind'], 'table' => $source['table']];
            }

            $events = array_merge($events, $rows);
        }

        // Deterministic order: ties on a busy day never rotate between
        // refreshes, so the controller's cap always cuts the same stable tail.
        usort($events, static fn (array $a, array $b): int => [$a['date'], $a['department'], $a['code']] <=> [$b['date'], $b['department'], $b['code']]);

        return ['checked' => $checked, 'skipped' => $skipped, 'truncated' => $truncated, 'events' => $events];
    }

    /**
     * The full source list: every WatchedDeadlines entry plus the
     * calendar-only ones, each normalised with kind / title / department /
     * view_permission. Public so the unit tests can pin that every source's
     * table prefix resolves in the department map — a new watcher entry with
     * an unmapped prefix must fail a test, not throw at request time.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function sources(): array
    {
        $registry = array_map(
            static fn (array $entry): array => $entry + [
                'kind' => $entry['key'],
                'title' => self::TITLE_OVERRIDES[$entry['key']] ?? "{$entry['label']} {$entry['date_word']}",
            ],
            WatchedDeadlines::entries(),
        );

        return array_map(
            static function (array $source): array {
                $override = self::AUDIENCE_OVERRIDES[$source['kind']] ?? [];

                return $source + [
                    'department' => $override['department'] ?? self::department($source['table']),
                    // {module}.view — NOT the registry's act permission
                    // (crm.update / fin.create): seeing WHEN something happens
                    // is reading (the GlobalSearch rule). Tenggat and the
                    // notifications keep the act permissions because they
                    // target the person who must act; do not "harmonise" the
                    // two — the asymmetry is the design.
                    'view_permission' => $override['permission'] ?? self::prefix($source['table']).'.view',
                ];
            },
            array_merge($registry, self::calendarOnly()),
        );
    }

    // ---------------------------------------------------------------- internal

    /**
     * The dates that are plans rather than obligations, declared in the
     * registry's own style so WatchedDeadlines::scoped()/missingSchema() apply
     * unchanged.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function calendarOnly(): array
    {
        $registry = [];

        foreach (WatchedDeadlines::entries() as $entry) {
            $registry[$entry['key']] = $entry;
        }

        return [
            [
                // fin_fiscal_periods stores year+month, not a date, so this is
                // the one bespoke resolver: the event is the LAST day of each
                // period month intersecting the window — tutup buku is the one
                // date the whole finance team plans around (demo: period
                // 2026-08 open → 'Tutup buku Agustus 2026' on 31 Agu). The
                // 'date'/'display' keys name month/year only so the shared
                // missingSchema() check covers them; the resolver reads both
                // columns itself.
                'kind' => 'fiscal_period_end',
                'table' => 'fin_fiscal_periods',
                'date' => 'month',
                'display' => 'year',
                'link' => 'periods',
                'resolver' => static fn (array $source, CarbonImmutable $from, CarbonImmutable $to): array => self::fiscalPeriodEnds($source, $from, $to),
            ],
            [
                // Hari gajian is the corporate event every employee plans
                // around. Any non-deleted status: a draft run's payment date is
                // still the plan. Demo: PYR/2026/03/001 (THR) dibayar 16 Mar
                // Rp 112,5 jt netto; PYR/2026/06/002 25 Jun Rp 166,6 jt — no
                // August run exists yet, and the calendar does not synthesize
                // a recurring payday.
                'kind' => 'payroll_payment',
                'table' => 'hr_payroll_runs',
                'date' => 'payment_date',
                'display' => 'code',
                'value' => 'total_net',
                'title' => 'Pembayaran gaji',
                'link' => 'r/hr/payroll-runs',
                'columns' => ['deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query->whereNull('deleted_at'),
            ],
            [
                // A start that happened is history worth seeing when browsing
                // back (PRJ-2026-001 mulai 2 Feb 2026); only deletion removes
                // it.
                'kind' => 'project_start',
                'table' => 'prj_projects',
                'date' => 'start_date',
                'display' => 'code',
                'title' => 'Proyek mulai',
                'link' => 'r/projects',
                'columns' => ['deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query->whereNull('deleted_at'),
            ],
            [
                // Same parent rule as the milestone watcher: the target date
                // of an on-hold/closed project will never be met as planned,
                // so it is not a plan to show (ProjectStatus::OnHold/::Closed).
                // Demo: PRJ-2026-002 target selesai 18 Des 2026.
                'kind' => 'project_end',
                'table' => 'prj_projects',
                'date' => 'end_date',
                'display' => 'code',
                'title' => 'Target selesai proyek',
                'link' => 'r/projects',
                'columns' => ['status', 'deleted_at'],
                /*
                 * Status hanya menyaring MASA DEPAN: target sebuah proyek
                 * on-hold/closed tidak akan tercapai sesuai rencana, jadi
                 * bukan rencana untuk ditampilkan — tetapi bulan LAMPAU
                 * adalah sejarah. PRJ-2026-002 (target 18 Des 2026) yang
                 * ditutup Januari harus tetap tampil saat Desember dibuka
                 * kembali, atau sejarah terbaca seolah rencananya tak pernah
                 * ada — sementara project_start-nya tetap tampil.
                 */
                'scope' => static fn (Builder $query): Builder => $query
                    ->whereNull('deleted_at')
                    ->where(static fn (Builder $either) => $either
                        ->whereNotIn('status', ['on_hold', 'closed'])
                        ->orWhere('end_date', '<', now()->toDateString())),
            ],
            [
                // Kontrak mulai berlaku (CTR/2026/I/0001 mulai 2 Feb 2026).
                // sign_date is deliberately NOT a source: retrospective
                // paperwork that duplicates start_date within days. Scope and
                // columns are the registry's contract_end entry REUSED —
                // "still somebody's problem" is the same test for both ends of
                // the span, and defining it twice is how they drift.
                'kind' => 'contract_start',
                'table' => 'crm_contracts',
                'date' => 'start_date',
                'display' => 'code',
                'value' => 'value',
                'title' => 'Kontrak mulai berlaku',
                'link' => 'r/crm/contracts',
                'columns' => $registry['contract_end']['columns'],
                'scope' => $registry['contract_end']['scope'],
            ],
            [
                // A scheduled serah terima. Any non-deleted status: a draft
                // BAST's handover date is the plan the site mobilises around.
                // (Table empty in the demo — the registry's retention watcher
                // covers the money side once rows exist.)
                'kind' => 'bast_handover',
                'table' => 'prj_bast',
                'date' => 'handover_date',
                'display' => 'code',
                'title' => 'Serah terima (BAST)',
                'link' => 'r/projects/bast',
                'columns' => ['deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query->whereNull('deleted_at'),
            ],
            [
                // The registry EXCLUDES this table because svc:generate-pm
                // converts due schedules into tickets, so no overdue alarm is
                // needed — but a calendar shows the plan itself (teknisi and
                // customer schedule around the visit), so the exclusion
                // argument does not transfer. Scope mirrors PreventiveService's
                // own guard: active schedule on an active contract. Demo Aug
                // 2026: PM CCTV Bulanan 5 Agu, PM Akses Kontrol & Alarm
                // Bulanan 12 Agu (SVC/2026/III/0001). title_from_display: a
                // named plan is its own best title.
                'kind' => 'pm_visit',
                'table' => 'svc_preventive_schedules',
                'date' => 'next_due_date',
                'display' => 'name',
                'title_from_display' => true,
                'link' => 'r/servicedesk/preventive-schedules',
                'requires' => ['svc_contracts'],
                'columns' => ['is_active', 'deleted_at', 'service_contract_id', 'svc_contracts.status', 'svc_contracts.deleted_at'],
                'scope' => static fn (Builder $query): Builder => $query
                    ->where('is_active', 1)
                    ->whereNull('deleted_at')
                    ->whereExists(static fn (Builder $contract) => $contract
                        ->select(DB::raw(1))
                        ->from('svc_contracts')
                        ->whereColumn('svc_contracts.id', 'svc_preventive_schedules.service_contract_id')
                        ->where('svc_contracts.status', 'active') // ServiceDesk ContractStatus::Active
                        ->whereNull('svc_contracts.deleted_at')),
            ],
        ];
    }

    /**
     * One source's rows inside the window, as events.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fetch(array $source, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $date = $source['date'];

        $columns = array_values(array_unique(array_filter([
            $source['display'],
            $date,
            $source['value'] ?? null,
        ])));

        // HALF-OPEN window on strings — the WatchedDeadlines footgun rule.
        // Model date casts store "2026-08-31 00:00:00", which sorts AFTER the
        // bare "2026-08-31": `< first-day-of-next-month` keeps the last day of
        // the month inside in BOTH storage forms, where a BETWEEN or `<= to`
        // would silently drop QTN/2026/VII/0004 (berlaku s/d 31 Agu) from its
        // own month.
        $rows = WatchedDeadlines::scoped($source)
            ->whereNotNull($date)
            ->where($date, '>=', $from->toDateString())
            ->where($date, '<', $to->addDay()->toDateString())
            ->orderBy($date)
            ->limit(self::FETCH_LIMIT)
            ->get($columns);

        return $rows->map(static fn (object $row): array => self::event(
            $source,
            substr((string) $row->{$date}, 0, 10),
            (string) $row->{$source['display']},
            isset($source['value']) ? (float) $row->{$source['value']} : null,
        ))->all();
    }

    /**
     * The bespoke fiscal-period resolver: last day of each period's month.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fiscalPeriodEnds(array $source, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Constrain to the window's months IN SQL so FETCH_LIMIT can never
        // starve the window behind a decade of older periods.
        $rows = DB::table('fin_fiscal_periods')
            ->where(static fn (Builder $query) => $query
                ->where('year', '>', $from->year)
                ->orWhere(static fn (Builder $same) => $same->where('year', $from->year)->where('month', '>=', $from->month)))
            ->where(static fn (Builder $query) => $query
                ->where('year', '<', $to->year)
                ->orWhere(static fn (Builder $same) => $same->where('year', $to->year)->where('month', '<=', $to->month)))
            ->orderBy('year')
            ->orderBy('month')
            ->limit(self::FETCH_LIMIT)
            ->get(['year', 'month']);

        $events = [];

        foreach ($rows as $row) {
            $last = CarbonImmutable::create((int) $row->year, (int) $row->month, 1)->endOfMonth()->toDateString();

            // A window need not start on the 1st; keep only last days inside.
            if ($last < $from->toDateString() || $last > $to->toDateString()) {
                continue;
            }

            $events[] = self::event(
                $source,
                $last,
                sprintf('%04d-%02d', (int) $row->year, (int) $row->month),
                null,
                'Tutup buku '.self::BULAN[(int) $row->month - 1].' '.$row->year,
            );
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(array $source, string $date, string $code, ?float $value, ?string $title = null): array
    {
        return [
            'date' => $date,
            'department' => $source['department'],
            'title' => $title ?? (($source['title_from_display'] ?? false) ? $code : $source['title']),
            'code' => $code,
            'link' => $source['link'],
            'kind' => $source['kind'],
            'value' => $value,
            // For CalendarController's filter only; stripped from the response.
            'permission' => $source['view_permission'],
        ];
    }

    private static function prefix(string $table): string
    {
        return (string) strstr($table, '_', true);
    }

    private static function department(string $table): string
    {
        return self::DEPARTMENTS[self::prefix($table)]
            ?? throw new \InvalidArgumentException("No department mapped for table [{$table}] — add its prefix to CalendarEvents::DEPARTMENTS.");
    }
}
