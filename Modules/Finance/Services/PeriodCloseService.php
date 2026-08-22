<?php

namespace Modules\Finance\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Enums\PeriodEventAction;
use Modules\Finance\Enums\PeriodStatus;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Models\PeriodEvent;
use Modules\Finance\Models\RevenueRecognitionRun;
use Modules\Finance\Support\DanglingDocuments;
use Modules\Finance\Support\MeasuredPeriods;
use Modules\Finance\Support\PeriodCostInputs;

/**
 * Disiplin periode: what "tutup buku" actually asserts, and what it costs.
 *
 * Closing a fiscal period is the moment a company stops being able to change a
 * month it has already reported. Until this service existed the whole act was a
 * status column somebody could set — and `assertPeriodOpen()`, the guard the
 * entire posting layer runs through, was therefore near-vacuous: every period
 * the seeder made was open and nothing ever closed one.
 *
 * THE CHECKLIST IS COMPUTED, NEVER STORED AND TRUSTED. Eleven items, recomputed
 * from the source tables on every request. Five are hard blocks; six are
 * warnings a closer may override by naming every one of them and writing a
 * reason that is kept forever. The JSON snapshot written into fin_period_events
 * is evidence — "this is what the closer was looking at" — and is never read
 * back as a gate. close() recomputes inside its own transaction rather than
 * trusting what the screen posted, for the same reason
 * RevenueRecognitionService::post() recomputes its draft: between drawing the
 * screen and pressing the button a draft journal can appear, and a close that
 * trusted the screen would make that race representable.
 *
 * ORDER. Periods close strictly oldest-first and reopen strictly newest-first.
 * The value of a close is the sentence "nothing dated on or before 31 May can
 * move any more"; with April still open that sentence is false, and
 * ReportService::trialBalance derives May's opening column from everything
 * before 1 May, so an open April makes May's opening balance a moving number.
 * Reopening runs the same rule backwards: opening March underneath a closed
 * April and May would push March's changes into opening balances that are
 * closed and cannot be re-derived.
 *
 * THE CALENDAR IS CREATED FORWARD, NEVER LAZILY. See ensureCalendar().
 */
class PeriodCloseService
{
    public const BLOCK = 'block';

    public const WARN = 'warn';

    public const OK = 'ok';

    public const FAIL = 'fail';

    public const NA = 'na';

    private const NOTE_MIN = 10;

    public function __construct(
        private readonly ReportService $reports,
        private readonly BankReconciliationService $bank,
        private readonly TaxExportService $taxes,
    ) {}

    // ------------------------------------------------------------- checklist

    /**
     * The eleven items, computed from source data. Never cached, never stored.
     *
     * Returned in CLOSE ORDER, which is why the screen reading them top to
     * bottom is also the month-end runbook.
     *
     * @return array<int, array<string, mixed>>
     */
    public function checklist(int $year, int $month): array
    {
        $period = FiscalPeriod::query()->where('year', $year)->where('month', $month)->first()
            ?? new FiscalPeriod(['year' => $year, 'month' => $month, 'status' => PeriodStatus::Open]);

        return [
            $this->itemPeriodEnded($period),
            $this->itemEarlierPeriodsClosed($period),
            $this->itemPayrollPresent($period),
            $this->itemDepreciationPresent($period),
            $this->itemPlantAccrued($period),
            $this->itemDanglingDocuments($period),
            $this->itemRevenueRecognition($period),
            $this->itemTrialBalance($period),
            $this->itemSubledgerTied($period),
            $this->itemBankReconciled($period),
            $this->itemTaxExportReady($period),
        ];
    }

    /**
     * The full screen payload for one period: identity, checklist, summary and
     * the permanent event history.
     */
    public function payload(FiscalPeriod $period): array
    {
        $items = $this->checklist($period->year, $period->month);
        $period->loadMissing(['closedBy:id,name', 'events.user:id,name']);

        return [
            'id' => $period->id,
            'year' => $period->year,
            'month' => $period->month,
            'code' => $period->code(),
            'label' => $period->label(),
            'period_start' => $period->periodStart(),
            'period_end' => $period->periodEnd(),
            'status' => $period->status->value,
            'status_label' => $period->status->label(),
            'is_closed' => $period->isClosed(),
            'has_ended' => $period->hasEnded(),
            'is_current' => $period->isCurrent(),
            'closed_at' => $period->closed_at?->toIso8601String(),
            'closed_by' => $period->closedBy === null
                ? null
                : ['id' => $period->closedBy->id, 'name' => $period->closedBy->name],
            'items' => $items,
            'summary' => $this->summary($period, $items),
            'events' => $period->events->map(fn (PeriodEvent $event): array => [
                'id' => $event->id,
                'action' => $event->action->value,
                'action_label' => $event->action->label(),
                'user_id' => $event->user_id,
                'user_name' => $event->user?->name,
                'note' => $event->note,
                'overrides' => $event->overrides ?? [],
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function summary(FiscalPeriod $period, array $items): array
    {
        $blockers = $this->failing($items, self::BLOCK);
        $warnings = $this->failing($items, self::WARN);
        $reopenRefusal = $period->isClosed() ? $this->reopenRefusal($period) : "Periode {$period->label()} tidak sedang ditutup.";

        return [
            'blockers' => count($blockers),
            'warnings' => count($warnings),
            'can_close' => $period->isOpen() && $blockers === [],
            'close_blocked_reason' => match (true) {
                $period->isClosed() => "Periode {$period->label()} sudah ditutup.",
                $blockers !== [] => count($blockers).' penghalang keras belum selesai.',
                default => null,
            },
            'can_reopen' => $reopenRefusal === null,
            'reopen_blocked_reason' => $reopenRefusal,
        ];
    }

    // ------------------------------------------------------------ the items

    private function itemPeriodEnded(FiscalPeriod $period): array
    {
        $ended = $period->hasEnded();

        return $this->item(
            'period_ended',
            'Periode sudah berakhir',
            self::BLOCK,
            $ended ? self::OK : self::FAIL,
            $ended
                ? "Periode berakhir {$period->periodEnd()}."
                : "Periode {$period->label()} berakhir {$period->periodEnd()} dan belum lewat. "
                    .'Bulan yang masih berjalan tidak dapat ditutup — dokumen masih akan bertanggal di dalamnya.',
            'periode belum berakhir',
        );
    }

    private function itemEarlierPeriodsClosed(FiscalPeriod $period): array
    {
        $oldestOpen = FiscalPeriod::query()
            ->where('status', PeriodStatus::Open->value)
            ->whereRaw('(year * 100 + month) < ?', [$period->key()])
            ->orderBy('year')->orderBy('month')
            ->first();

        // "Every EXISTING earlier period", not "every earlier month": an
        // installation whose calendar starts in July 2026 must not be wedged by
        // six months nobody ever created.
        if ($oldestOpen === null) {
            return $this->item('earlier_periods_closed', 'Periode sebelumnya sudah ditutup', self::BLOCK, self::OK,
                'Tidak ada periode lebih lama yang masih terbuka.', 'periode sebelumnya masih terbuka');
        }

        return $this->item(
            'earlier_periods_closed',
            'Periode sebelumnya sudah ditutup',
            self::BLOCK,
            self::FAIL,
            "Periode {$oldestOpen->label()} masih terbuka. Buku ditutup berurutan dari yang paling lama: "
                ."selama {$oldestOpen->label()} terbuka, saldo awal {$period->label()} masih bisa berubah "
                .'di belakangnya dan laporan yang sudah dibekukan ikut bergeser.',
            "periode {$oldestOpen->code()} masih terbuka",
            'periods',
            1,
        );
    }

    /**
     * A MISSING payroll run is a warning, never a block.
     *
     * A company whose HR module is not live yet would otherwise be wedged out
     * of ever closing a month, and a hard block the business cannot satisfy is
     * as bad as no control at all. A DRAFT run for the period is a different
     * thing entirely and IS a hard block, through dangling_documents.
     */
    private function itemPayrollPresent(FiscalPeriod $period): array
    {
        $hasEmployees = DB::table('hr_employees')->whereNull('deleted_at')->exists();

        if (! $hasEmployees) {
            return $this->item('payroll_present', 'Payroll bulan ini sudah ada', self::WARN, self::NA,
                'Belum ada data karyawan, jadi tidak ada penggajian yang diharapkan.', 'payroll belum ada');
        }

        $present = PeriodCostInputs::hasPayrollRun($period->year, $period->month);

        return $this->item(
            'payroll_present',
            'Payroll bulan ini sudah ada',
            self::WARN,
            $present ? self::OK : self::FAIL,
            $present
                ? "Payroll untuk {$period->label()} sudah dibuat."
                : "Belum ada payroll untuk {$period->label()}. Bila memang tidak ada penggajian bulan ini, "
                    .'abaikan peringatan ini; bila ada, upah bulan ini tidak akan pernah masuk ke buku besar '
                    .'bulan ini dan biaya proyek understated selamanya.',
            'payroll bulan ini belum ada',
            'r/hr/payroll-runs',
        );
    }

    /**
     * Same reasoning as payroll, with one extra consequence worth naming:
     * DepreciationService::runForPeriod() refuses any period at or before the
     * last posted one, so a month skipped here can never be depreciated later.
     */
    private function itemDepreciationPresent(FiscalPeriod $period): array
    {
        $hasDepreciable = DB::table('ast_assets')
            ->whereNull('deleted_at')
            ->where('useful_life_months', '>', 0)
            ->exists();

        if (! $hasDepreciable) {
            return $this->item('depreciation_present', 'Penyusutan bulan ini sudah ada', self::WARN, self::NA,
                'Belum ada aset yang disusutkan.', 'penyusutan belum ada');
        }

        $present = PeriodCostInputs::hasDepreciationRun($period->year, $period->month);

        return $this->item(
            'depreciation_present',
            'Penyusutan bulan ini sudah ada',
            self::WARN,
            $present ? self::OK : self::FAIL,
            $present
                ? "Run penyusutan untuk {$period->label()} sudah dibuat."
                : "Belum ada run penyusutan untuk {$period->label()}. Penyusutan hanya berjalan maju — "
                    .'periode pada atau sebelum periode terakhir yang sudah diposting ditolak — jadi bulan '
                    .'yang dilewati tidak dapat disusutkan menyusul dan beban itu hilang permanen.',
            'penyusutan bulan ini belum ada',
            'r/assets/depreciation-runs',
        );
    }

    /**
     * Internal plant accrual (T43): every machine still on site with an
     * internal daily rate must have its accrual row for this month before the
     * month closes, because after the close it never can — ProjectCostService
     * refuses cost rows dated inside a closed period, so a month that closes
     * unaccrued keeps its equipment realisasi understated for ever and the
     * whole span surfaces at demobilisation, dated the return day. The
     * cumulative figure stays right; this month's does not, permanently.
     *
     * WARN, not BLOCK: the charge is a management allocation that never
     * touches the general ledger or the trial balance, so the closer may
     * accept the understatement in writing — the same bar as a missing
     * payroll run. The item belongs on the checklist rather than only in a
     * nightly schedule because a cron that misses a month fails silently;
     * a checklist line does not.
     *
     * Only ACTIVE deployments are counted. A returned one settled its whole
     * span at demobilisation (DeploymentService charges the residual of days
     * no accrual covered), so an accrual for it now would double count —
     * there is nothing left for the closer to run.
     */
    private function itemPlantAccrued(FiscalPeriod $period): array
    {
        $label = 'Akrual alat internal bulan ini sudah dicatat';
        $short = 'akrual alat bulan ini belum dicatat';

        if (! $period->hasEnded()) {
            return $this->item('plant_accrued', $label, self::WARN, self::NA,
                "Periode {$period->label()} belum berakhir — akrual alat baru dapat dijalankan setelah bulannya berakhir.",
                $short);
        }

        $open = DB::table('ast_deployments')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->whereNotNull('project_id')
            ->where('daily_rate_internal', '>', 0)
            ->whereDate('deployed_from', '<=', $period->periodEnd())
            ->orderBy('deployed_from')
            ->get(['id', 'code', 'deployed_from', 'daily_rate_internal']);

        if ($open->isEmpty()) {
            return $this->item('plant_accrued', $label, self::WARN, self::NA,
                'Tidak ada mobilisasi alat internal bertarif yang terbuka pada periode ini.', $short);
        }

        // Same arithmetic as DeploymentService::monthReferenceId — the
        // reference_id of one (deployment, month) accrual row carries both
        // halves: id * 1.000.000 + tahun * 100 + bulan.
        $accrued = DB::table('fin_project_costs')
            ->where('reference_type', 'asset_deployment_month')
            ->where('cost_category', 'equipment')
            ->whereIn('reference_id', $open->map(fn (object $row): int => (int) $row->id * 1_000_000 + $period->key()))
            ->pluck('reference_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $start = CarbonImmutable::parse($period->periodStart());
        $end = CarbonImmutable::parse($period->periodEnd());
        $missing = [];
        $total = 0.0;

        foreach ($open as $row) {
            if (in_array((int) $row->id * 1_000_000 + $period->key(), $accrued, true)) {
                continue;
            }

            $from = CarbonImmutable::parse($row->deployed_from);
            $from = $from->greaterThan($start) ? $from : $start;
            // Inclusive of both ends — DeploymentService's day-counting rule.
            $days = $from->diffInDays($end) + 1;
            $amount = round($days * (float) $row->daily_rate_internal, 2);
            $total = round($total + $amount, 2);
            $missing[] = sprintf('%s (%d hari, %s)', $row->code, $days, number_format($amount, 0, ',', '.'));
        }

        if ($missing === []) {
            return $this->item('plant_accrued', $label, self::WARN, self::OK,
                count($open)." mobilisasi alat terbuka sudah diakru untuk {$period->label()}.",
                $short, 'r/assets/deployments');
        }

        $shown = array_slice($missing, 0, 3);
        $more = count($missing) - count($shown);

        return $this->item(
            'plant_accrued',
            $label,
            self::WARN,
            self::FAIL,
            count($missing)." mobilisasi alat masih di lokasi tanpa akrual {$period->label()}: ".implode(', ', $shown)
                .($more > 0 ? ", dan {$more} lagi" : '')
                .' — total '.number_format($total, 0, ',', '.')
                .". Jalankan `php artisan ast:accrue-plant {$period->year} {$period->month}` sebelum menutup: "
                .'setelah periode ditutup, penjaga periode menolak baris biaya bertanggal di dalamnya selamanya, '
                .'dan biaya alat bulan ini baru muncul menumpuk pada tanggal demobilisasi.',
            $short,
            'r/assets/deployments',
            count($missing),
        );
    }

    private function itemDanglingDocuments(FiscalPeriod $period): array
    {
        $scan = DanglingDocuments::scan($period->year, $period->month);
        $count = DanglingDocuments::total($scan);

        if ($count === 0) {
            return $this->item('dangling_documents', 'Tidak ada dokumen menggantung', self::BLOCK, self::OK,
                "Semua dokumen bertanggal di {$period->label()} sudah diposting, ditolak, atau dibatalkan.",
                'dokumen menggantung');
        }

        $codes = [];

        foreach ($scan as $source) {
            foreach ($source['codes'] as $code) {
                $codes[] = $code;
            }
        }

        $shown = array_slice($codes, 0, 5);
        $more = $count - count($shown);

        return $this->item(
            'dangling_documents',
            'Tidak ada dokumen menggantung',
            self::BLOCK,
            self::FAIL,
            "{$count} dokumen bertanggal di {$period->label()} belum diposting: ".implode(', ', $shown)
                .($more > 0 ? ", dan {$more} lagi" : '')
                .'. Setelah periode ditutup, dokumen ini tidak akan pernah bisa diposting pada tanggalnya — '
                .'posting, hapus, atau ubah tanggalnya lebih dulu.',
            "{$count} dokumen menggantung bertanggal di dalamnya",
            $scan[0]['link'],
            $count,
            ['sources' => $scan],
        );
    }

    /**
     * The only item whose omission is both unrecoverable AND cascading, hence a
     * hard block: after the close the run can never be posted (post() requires
     * an open period), and previousBalances() reads the last POSTED run, so a
     * skipped May is silently folded into June. The balance sheet stays right
     * while May's income statement — already issued — is permanently on a
     * billing basis and June's is overstated by the same amount.
     *
     * TWO DEGRADATIONS, both because a block nobody can satisfy is worse than
     * no block. (a) A posted run for a LATER period makes this one unpostable
     * by design (post() enforces forward-only order), which is reachable on any
     * period predating this package — the item becomes an overridable warning
     * naming the later run. (b) No contract has ever been in PSAK 115 scope, so
     * there is no performance obligation to measure and no run to demand; the
     * item is not applicable, and the block returns the moment a construction
     * or system-integration contract is approved.
     */
    private function itemRevenueRecognition(FiscalPeriod $period): array
    {
        $inScope = DB::table('crm_contracts')
            ->whereNull('deleted_at')
            ->whereIn('scope_type', ['construction', 'system_integration'])
            ->whereIn('status', ['approved', 'closed'])
            ->exists();

        if (! $inScope) {
            return $this->item('revenue_recognition_posted', 'Pengakuan pendapatan PSAK 115 sudah diposting',
                self::BLOCK, self::NA,
                'Belum ada kontrak dalam lingkup PSAK 115, jadi tidak ada yang perlu diukur bulan ini.',
                'run PSAK 115 belum diposting');
        }

        $run = RevenueRecognitionRun::query()
            ->where('period_year', $period->year)
            ->where('period_month', $period->month)
            ->first();

        if ($run !== null && $run->isPosted()) {
            return $this->item('revenue_recognition_posted', 'Pengakuan pendapatan PSAK 115 sudah diposting',
                self::BLOCK, self::OK,
                "Run {$run->code} sudah diposting untuk {$period->label()}.",
                'run PSAK 115 belum diposting', 'r/finance/revenue-recognition');
        }

        $later = RevenueRecognitionRun::query()
            ->where('status', PostingStatus::Posted->value)
            ->whereRaw('(period_year * 100 + period_month) > ?', [$period->key()])
            ->orderBy('period_year')->orderBy('period_month')
            ->first();

        if ($later !== null) {
            return $this->item('revenue_recognition_posted', 'Pengakuan pendapatan PSAK 115 sudah diposting',
                self::WARN, self::FAIL,
                sprintf(
                    'Run %s untuk periode %04d-%02d sudah diposting, sehingga %s tidak dapat diukur lagi — '
                        .'pengakuan pendapatan hanya berjalan maju. Penutupan boleh dilanjutkan, tetapi bulan ini '
                        .'tetap tercatat di atas dasar penagihan, bukan persentase penyelesaian.',
                    $later->code, $later->period_year, $later->period_month, $period->label(),
                ),
                'run PSAK 115 tidak dapat lagi diposting',
                'r/finance/revenue-recognition');
        }

        return $this->item('revenue_recognition_posted', 'Pengakuan pendapatan PSAK 115 sudah diposting',
            self::BLOCK, self::FAIL,
            ($run === null
                ? "Belum ada run PSAK 115 untuk {$period->label()}."
                : "Run {$run->code} untuk {$period->label()} masih draf.")
                .' Setelah periode ditutup run ini tidak akan pernah bisa diposting, dan bulan berikutnya akan '
                .'menyerap penyesuaiannya — dua laporan laba rugi yang salah sekaligus.',
            'run PSAK 115 belum diposting',
            'r/finance/revenue-recognition');
    }

    /**
     * Reachable in practice only through direct database surgery — and that is
     * the point. It costs one query, and the thing it prevents (publishing a
     * balance sheet that does not balance) has no cheap correction afterwards.
     */
    private function itemTrialBalance(FiscalPeriod $period): array
    {
        $tb = $this->reports->trialBalance($period->year, $period->month);

        if ($tb['balanced']) {
            return $this->item('trial_balance_balanced', 'Neraca saldo seimbang', self::BLOCK, self::OK,
                'Debit dan kredit penutup seimbang.', 'neraca saldo tidak seimbang', 'reports');
        }

        return $this->item('trial_balance_balanced', 'Neraca saldo seimbang', self::BLOCK, self::FAIL,
            sprintf(
                'Neraca saldo %s tidak seimbang: debit %s vs kredit %s. Periode tidak boleh dibekukan '
                    .'di atas buku yang tidak seimbang.',
                $period->label(),
                number_format($tb['totals']['closing_debit'], 2, ',', '.'),
                number_format($tb['totals']['closing_credit'], 2, ',', '.'),
            ),
            'neraca saldo tidak seimbang', 'reports');
    }

    /**
     * Sub-ledger AP/AR vs their control accounts — the number a GL-only
     * settlement cannot fake.
     *
     * The manual-JV probe in the package-7 review settled a vendor with
     * Dr 2-1100 / Cr 1-1210 Rp 111.000.000: the trial balance stayed balanced,
     * the bank bridge still closed (the JV line matches the real bank debit),
     * and the bill's outstanding stayed Rp 111.000.000. The one place that
     * disagreement is visible is here — approved unpaid bills no longer sum
     * to 2-1100.
     *
     * The sub-ledger side is derived from documents minus posted payment
     * allocations AS AT PERIOD END — never from amount_paid, which is a
     * lifetime figure a July payment moves after June has closed. That basis
     * is now shared: ReportService::agingReport and CashFlowService::
     * projection read it through Support\OutstandingAsOf, so the number this
     * item warns about is the same number the AR/AP aging screen shows the
     * person sent to investigate the warning. Retention is
     * excluded on both sides deliberately: fin_ar_invoices.total and
     * fin_ap_bills.total_payable are already net of retention, whose balances
     * live in 1-1350 / 2-1500, not in the control accounts compared here.
     * (2-1500, not 2-1150: subcontractor retention is credited to
     * ApBillService::SUBCON_RETENTION_ACCOUNT = '2-1500', hardcoded and with
     * no settings override. 2-1150 is Penerimaan Barang Belum Ditagih, the
     * GR/IR clearing account — a maintainer who followed this docblock to
     * 2-1150 to understand why retention is excluded would find an unrelated
     * balance there and reason on from a false premise.)
     *
     * WARN, not BLOCK, because one legitimate difference exists by design: a
     * document cancelled after its period reverses on TODAY's date
     * (JournalService::reversalDate), so period-end GL still carries a
     * document the sub-ledger no longer shows. The closer names the difference
     * and writes why — which is exactly the investigation a tie-out demands.
     */
    private function itemSubledgerTied(FiscalPeriod $period): array
    {
        $end = $period->periodEnd();

        $arGl = $this->controlBalance('1-1300', $end);
        $apGl = $this->controlBalance('2-1100', $end);

        if ($arGl === null || $apGl === null) {
            return $this->item('subledger_tied', 'Sub-ledger AP/AR cocok dengan buku besar', self::WARN, self::NA,
                'Akun kontrol 1-1300 / 2-1100 belum ada di bagan akun, jadi tidak ada yang bisa dibandingkan.',
                'sub-ledger AP/AR tidak cocok dengan GL');
        }

        $ar = $this->subledgerOutstanding('fin_ar_invoices', 'invoice_date', 'total', PaymentAllocation::TYPE_AR_INVOICE, $end);
        $ap = $this->subledgerOutstanding('fin_ap_bills', 'bill_date', 'total_payable', PaymentAllocation::TYPE_AP_BILL, $end);

        // 2-1100 is credit-normal; both sides are compared as positive
        // outstanding so the operator reads hutang as hutang, not as −X.
        $sides = [
            ['label' => 'piutang', 'account' => '1-1300', 'gl' => $arGl, 'sub' => $ar, 'sub_label' => 'invoice termin disetujui belum lunas'],
            ['label' => 'hutang', 'account' => '2-1100', 'gl' => round(-$apGl, 2), 'sub' => $ap, 'sub_label' => 'tagihan vendor disetujui belum dibayar'],
        ];

        $broken = array_values(array_filter(
            $sides,
            // Whole-cent comparison, same reason as JournalService::assertBalanced.
            fn (array $side): bool => abs((int) round(($side['gl'] - $side['sub']) * 100)) > 1,
        ));

        if ($broken === []) {
            return $this->item('subledger_tied', 'Sub-ledger AP/AR cocok dengan buku besar', self::WARN, self::OK,
                sprintf(
                    'Per %s: 1-1300 %s = sisa piutang termin; 2-1100 %s = sisa hutang vendor.',
                    $end,
                    number_format($arGl, 2, ',', '.'),
                    number_format($ap, 2, ',', '.'),
                ),
                'sub-ledger AP/AR tidak cocok dengan GL');
        }

        $named = implode('; ', array_map(
            fn (array $side): string => sprintf(
                '%s: GL %s %s vs %s %s (selisih %s)',
                $side['label'],
                $side['account'],
                number_format($side['gl'], 2, ',', '.'),
                $side['sub_label'],
                number_format($side['sub'], 2, ',', '.'),
                number_format(round($side['gl'] - $side['sub'], 2), 2, ',', '.'),
            ),
            $broken,
        ));

        return $this->item('subledger_tied', 'Sub-ledger AP/AR cocok dengan buku besar', self::WARN, self::FAIL,
            "Saldo buku besar per {$end} tidak sama dengan sisa dokumen yang disetujui — {$named}. "
                .'Selisih di sini berarti buku besar digerakkan di luar sub-ledger: biasanya JV manual '
                .'pada 1-1300/2-1100, atau pembalikan pembatalan yang bertanggal setelah akhir periode. '
                .'Telusuri jurnalnya sebelum menutup.',
            'sub-ledger AP/AR tidak cocok dengan GL',
            'r/finance/journals',
            count($broken));
    }

    /**
     * Posted balance of one control account as at $end, debit-normal sign;
     * null when the account is not in the chart (nothing to compare against).
     */
    private function controlBalance(string $accountCode, string $end): ?float
    {
        $accountId = DB::table('fin_accounts')->where('code', $accountCode)->value('id');

        if ($accountId === null) {
            return null;
        }

        $sums = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            // whereDate, same reason as everywhere: journal_date is cast `date`
            // and stored "…-06-30 00:00:00", which a raw string <= drops on the
            // last day of the month.
            ->whereDate('fin_journals.journal_date', '<=', $end)
            ->where('fin_journal_lines.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        return round((float) $sums->d - (float) $sums->c, 2);
    }

    /**
     * What the approved documents say is still unpaid AS AT $end: document
     * values minus allocations of payments POSTED and dated inside the window.
     * Allocations are joined back to the approved documents, so a cancelled
     * document drops out of both terms at once.
     */
    private function subledgerOutstanding(
        string $table,
        string $dateColumn,
        string $valueColumn,
        string $payableType,
        string $end,
    ): float {
        $documents = round((float) DB::table($table)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->whereDate($dateColumn, '<=', $end)
            ->sum($valueColumn), 2);

        $settled = round((float) DB::table('fin_payment_allocations')
            ->join('fin_payments', 'fin_payments.id', '=', 'fin_payment_allocations.payment_id')
            ->join($table, "{$table}.id", '=', 'fin_payment_allocations.payable_id')
            ->where('fin_payment_allocations.payable_type', $payableType)
            ->where('fin_payments.status', PaymentStatus::Posted->value)
            ->whereNull('fin_payments.deleted_at')
            ->whereDate('fin_payments.payment_date', '<=', $end)
            ->where("{$table}.status", 'approved')
            ->whereNull("{$table}.deleted_at")
            ->whereDate("{$table}.{$dateColumn}", '<=', $end)
            ->sum('fin_payment_allocations.amount'), 2);

        return round($documents - $settled, 2);
    }

    /**
     * NEVER a block. The statement arrives days to weeks after month end, from
     * a third party — BankReconciliationController's own docblock already says
     * reconciling a closed month is the normal case and deliberately does not
     * gate on fiscal periods. Blocking here would make the close hostage to the
     * post office.
     */
    private function itemBankReconciled(FiscalPeriod $period): array
    {
        $rows = $this->bank->overview($period->periodEnd())['rows'];

        if ($rows === []) {
            return $this->item('bank_reconciled', 'Rekening bank sudah terekonsiliasi', self::WARN, self::NA,
                'Belum ada rekening bank aktif.', 'rekonsiliasi bank');
        }

        $open = array_values(array_filter($rows, fn (array $row): bool => ! $row['fully_reconciled']));

        if ($open === []) {
            return $this->item('bank_reconciled', 'Rekening bank sudah terekonsiliasi', self::WARN, self::OK,
                count($rows).' rekening terekonsiliasi sampai '.$period->periodEnd().'.',
                'rekonsiliasi bank', 'bank-recon');
        }

        // A blocked account carries WHY it could not be reconciled ("rekening
        // koran belum diimpor", the shared-COA refusal). Printing open_items
        // for it said "0 item terbuka" — a warning whose own text claims
        // nothing is outstanding — and threw away the one sentence the closer
        // needed before overriding.
        $named = implode(', ', array_map(
            fn (array $row): string => $row['bank_account']['name']
                .' ('.($row['blocked'] ?? $row['open_items'].' item terbuka').')',
            array_slice($open, 0, 3),
        ));

        return $this->item('bank_reconciled', 'Rekening bank sudah terekonsiliasi', self::WARN, self::FAIL,
            count($open)." rekening belum terekonsiliasi sampai {$period->periodEnd()}: {$named}. "
                .'Rekening koran biasanya baru datang setelah tutup bulan, jadi ini tidak menghalangi '
                .'penutupan — tetapi selisih yang ditemukan nanti hanya bisa dikoreksi bertanggal hari ini.',
            'rekonsiliasi bank', 'bank-recon', count($open));
    }

    /**
     * NEVER a block either. The commonest blocker is a faktur pajak serial
     * number that comes FROM DJP, and the export reads posted documents rather
     * than posting anything — it can be produced after the books close.
     */
    private function itemTaxExportReady(FiscalPeriod $period): array
    {
        try {
            $overview = $this->taxes->overview($period->year, $period->month);
        } catch (LogicException $e) {
            return $this->item('tax_export_ready', 'Ekspor pajak siap', self::WARN, self::NA,
                $e->getMessage(), 'ekspor pajak');
        }

        $blockers = array_merge($overview['efaktur']['blockers'], $overview['ebupot']['blockers']);
        $documents = count($overview['efaktur']['rows']) + count($overview['ebupot']['rows']) + count($blockers);

        if ($documents === 0) {
            return $this->item('tax_export_ready', 'Ekspor pajak siap', self::WARN, self::NA,
                "Tidak ada dokumen pajak keluaran/potongan di {$period->label()}.", 'ekspor pajak');
        }

        if ($blockers === []) {
            return $this->item('tax_export_ready', 'Ekspor pajak siap', self::WARN, self::OK,
                "{$documents} dokumen siap diekspor ke DJP.", 'ekspor pajak', 'tax-exports');
        }

        $named = implode(', ', array_map(
            fn (array $row): string => $row['document'].' — '.$row['reason'],
            array_slice($blockers, 0, 3),
        ));

        return $this->item('tax_export_ready', 'Ekspor pajak siap', self::WARN, self::FAIL,
            count($blockers)." dokumen belum siap diekspor: {$named}. Nomor seri faktur datang dari DJP dan "
                .'ekspor hanya membaca dokumen yang sudah diposting, jadi ini boleh diselesaikan setelah '
                .'buku ditutup.',
            'ekspor pajak', 'tax-exports', count($blockers));
    }

    // --------------------------------------------------------------- closing

    /**
     * Close the period, or refuse and say exactly why.
     *
     * @param  array<int, string>  $acknowledge  warning keys the closer accepts
     */
    public function close(FiscalPeriod $period, User $by, ?string $note = null, array $acknowledge = []): FiscalPeriod
    {
        return DB::transaction(function () use ($period, $by, $note, $acknowledge): FiscalPeriod {
            /*
             * lockForUpdate() is a silent no-op on SQLite, so the status
             * re-read below is the actual protection: two closers racing each
             * other both reach this point, and the second one finds `closed`.
             */
            /** @var FiscalPeriod $period */
            $period = FiscalPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if (! $period->isOpen()) {
                throw new LogicException("Periode {$period->label()} sudah ditutup.");
            }

            // Recomputed here, not read from the request: the checklist the
            // screen drew may be seconds old and a draft journal may have
            // appeared inside those seconds.
            $items = $this->checklist($period->year, $period->month);

            $blockers = $this->failing($items, self::BLOCK);

            if ($blockers !== []) {
                throw new LogicException(
                    "Periode {$period->label()} belum dapat ditutup: "
                    .implode('; ', array_column($blockers, 'short')).'.'
                );
            }

            $warnings = $this->failing($items, self::WARN);
            $acknowledged = array_values(array_unique(array_map('strval', $acknowledge)));
            $missing = array_values(array_diff(array_column($warnings, 'key'), $acknowledged));

            if ($missing !== []) {
                $labels = array_map(
                    fn (string $key): string => $this->itemByKey($warnings, $key)['short'],
                    $missing,
                );

                throw new LogicException(
                    'Peringatan berikut belum diakui: '.implode(', ', $labels)
                    .'. Centang semuanya dan tulis alasannya.'
                );
            }

            // Only warnings that actually FAILED count as overrides — a screen
            // that ticks every box on a clean period must not be forced to
            // invent a reason for nothing.
            $overrides = array_column($warnings, 'key');
            $note = trim((string) $note);

            if ($overrides !== [] && mb_strlen($note) < self::NOTE_MIN) {
                throw new LogicException(
                    'Alasan wajib diisi bila ada peringatan yang diabaikan — minimal '
                    .self::NOTE_MIN.' karakter, dan tercatat permanen.'
                );
            }

            $period->forceFill([
                'status' => PeriodStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $by->id,
            ])->save();

            $this->recordEvent($period, PeriodEventAction::Closed, $by, $note === '' ? null : $note, $overrides, $items);

            return $period->refresh();
        });
    }

    /**
     * Reopen the newest closed period, or refuse and name the alternative.
     *
     * A note is REQUIRED. Reopening alters figures that have already been
     * reported; the row it writes is the only thing that makes that visible
     * afterwards.
     */
    public function reopen(FiscalPeriod $period, User $by, ?string $note = null): FiscalPeriod
    {
        $note = trim((string) $note);

        if (mb_strlen($note) < self::NOTE_MIN) {
            throw new LogicException('Alasan membuka periode wajib diisi — ini tercatat permanen.');
        }

        return DB::transaction(function () use ($period, $by, $note): FiscalPeriod {
            /** @var FiscalPeriod $period */
            $period = FiscalPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if (! $period->isClosed()) {
                throw new LogicException("Periode {$period->label()} tidak sedang ditutup.");
            }

            $refusal = $this->reopenRefusal($period);

            if ($refusal !== null) {
                throw new LogicException($refusal);
            }

            $items = $this->checklist($period->year, $period->month);

            $period->forceFill([
                'status' => PeriodStatus::Open,
                'closed_at' => null,
                'closed_by' => null,
            ])->save();

            $this->recordEvent($period, PeriodEventAction::Reopened, $by, $note, [], $items);

            return $period->refresh();
        });
    }

    /**
     * Why this period may not be reopened, or null when it may.
     *
     * Rendered next to a DISABLED button rather than hiding the button: a
     * control the user cannot see is a control they will ask about by email.
     */
    public function reopenRefusal(FiscalPeriod $period): ?string
    {
        if (! $period->isClosed()) {
            return "Periode {$period->label()} tidak sedang ditutup.";
        }

        $newerClosed = FiscalPeriod::query()
            ->where('status', PeriodStatus::Closed->value)
            ->whereRaw('(year * 100 + month) > ?', [$period->key()])
            ->orderBy('year')->orderBy('month')
            ->first();

        if ($newerClosed !== null) {
            return "Periode {$period->label()} tidak dapat dibuka: periode {$newerClosed->label()} masih "
                .'tertutup di atasnya. Buka periode terbaru lebih dulu.';
        }

        $run = MeasuredPeriods::postedRunAtOrAfter($period->key());

        if ($run === null) {
            return null;
        }

        $sameMonth = $run->period_year === $period->year && $run->period_month === $period->month;

        // The run's own period is spelled out even in the same-month branch:
        // HasDocumentNumber mints the code from TODAY's month, so a June run
        // posted in August is called POC/2026/08/001 and "Periode Juni 2026
        // sudah diukur oleh POC/2026/08/001" read like a mismatch.
        return $sameMonth
            ? sprintf(
                'Periode %s sudah diukur oleh run PSAK 115 %s (periode %04d-%02d) yang terposting. '
                    .'Run yang sudah diposting tidak dapat dihitung ulang, jadi periode ini tidak dapat dibuka '
                    .'lagi — koreksi yang ditemukan hari ini dibukukan hari ini.',
                $period->label(), $run->code, $run->period_year, $run->period_month,
            )
            : sprintf(
                'Periode %s berada sebelum run PSAK 115 %s (periode %04d-%02d) yang sudah terposting. '
                    .'Run yang sudah diposting tidak dapat dihitung ulang, jadi biaya yang masuk ke %s hanya '
                    .'akan menggeser persentase bulan berikutnya — periode ini tidak dapat dibuka lagi. '
                    .'Koreksi yang ditemukan hari ini dibukukan hari ini.',
                $period->label(), $run->code, $run->period_year, $run->period_month, $period->label(),
            );
    }

    // -------------------------------------------------------------- calendar

    /**
     * Create every missing month from this one through $monthsAhead, open.
     *
     * LAZY CREATION INSIDE assertPeriodOpen() IS REJECTED, and this command is
     * why it can be. Creating the period on demand would create it OPEN at the
     * exact moment somebody posted into it: backdating a journal to 2025-03-15
     * would create-and-open March 2025 and then accept the entry — the guard
     * defeating itself at the one thing it exists to prevent. It would also
     * leave an open period behind a wall of closed ones, breaking the ordering
     * invariant retroactively.
     *
     * Three months forward, daily, means the 2027 calendar exists from
     * 1 October 2026, long before anyone dates a document into it, and a server
     * that was down for two months heals on its first run. A monthly schedule
     * that missed the 1st would leave the gap open until somebody noticed on
     * 1 January — which is precisely the failure being fixed.
     *
     * @return array<int, array{year: int, month: int, new_year: bool}>
     */
    public function ensureCalendar(int $monthsAhead): array
    {
        $start = CarbonImmutable::today()->startOfMonth();
        $created = [];

        for ($i = 0; $i <= max(0, $monthsAhead); $i++) {
            $target = $start->addMonths($i);

            if (FiscalPeriod::query()->where('year', $target->year)->where('month', $target->month)->exists()) {
                continue;
            }

            $newYear = ! FiscalPeriod::query()->where('year', $target->year)->exists();

            // firstOrCreate, never updateOrCreate: an existing row's status is
            // never touched, so a closed period cannot be reopened by cron.
            FiscalPeriod::query()->firstOrCreate(
                ['year' => $target->year, 'month' => $target->month],
                ['status' => PeriodStatus::Open->value],
            );

            $created[] = ['year' => $target->year, 'month' => $target->month, 'new_year' => $newYear];
        }

        return $created;
    }

    /**
     * Create a whole year on request.
     *
     * THE PAST-YEAR RULE, in two halves. A month is created CLOSED when a
     * CLOSED period already lies after it, OR when it lies strictly before the
     * earliest period the calendar has at all.
     *
     * The first half: "buat kalender 2025" on a system where 2026-01 is closed
     * must not open twelve months behind a closed one — that breaks the
     * ordering invariant and opens a backdating hole in the same click.
     *
     * The second half exists because the first was vacuous on any installation
     * where nothing had ever been closed: with the 2026 calendar all open, the
     * review probe generated 2024 as twelve OPEN months and then posted a
     * journal dated 2024-05-10 that assertPeriodOpen() had refused minutes
     * earlier — the endpoint (fin.create, which the `finance` role holds
     * alongside fin.post) undid the only guard against backdating. Twelve
     * closed 2024 periods is what "we started using this system in 2026" means
     * whether or not anyone has pressed Tutup Periode yet.
     */
    public function generateYear(int $year): array
    {
        $maxYear = CarbonImmutable::today()->year + 2;

        if ($year > $maxYear) {
            throw new LogicException(
                "Tahun {$year} terlalu jauh ke depan — kalender fiskal dibuat paling jauh 2 tahun dari sekarang."
            );
        }

        if ($year < 2000) {
            throw new LogicException("Tahun {$year} di luar rentang yang wajar — kalender fiskal dimulai dari tahun 2000.");
        }

        return DB::transaction(function () use ($year): array {
            $createdOpen = 0;
            $createdClosed = 0;
            $existing = 0;
            $behind = null;

            // Resolved BEFORE the loop: the months this very request creates
            // must not become "the earliest period" for their own siblings.
            $earliest = FiscalPeriod::query()
                ->orderBy('year')->orderBy('month')
                ->first();

            for ($month = 1; $month <= 12; $month++) {
                if (FiscalPeriod::query()->where('year', $year)->where('month', $month)->exists()) {
                    $existing++;

                    continue;
                }

                $closedAfter = FiscalPeriod::query()
                    ->where('status', PeriodStatus::Closed->value)
                    ->whereRaw('(year * 100 + month) > ?', [$year * 100 + $month])
                    ->orderBy('year')->orderBy('month')
                    ->first();

                $beforeCalendar = $earliest !== null
                    && ($year * 100 + $month) < $earliest->key();

                FiscalPeriod::query()->create([
                    'year' => $year,
                    'month' => $month,
                    'status' => ($closedAfter === null && ! $beforeCalendar)
                        ? PeriodStatus::Open->value
                        : PeriodStatus::Closed->value,
                ]);

                if ($closedAfter === null && ! $beforeCalendar) {
                    $createdOpen++;
                } else {
                    $createdClosed++;
                    $behind ??= $closedAfter;
                }
            }

            $created = $createdOpen + $createdClosed;

            // The reason the closed months are closed, for the message: a
            // closed period after them when there is one, otherwise the
            // calendar they predate.
            $because = $behind !== null
                ? "periode {$behind->code()} sudah ditutup"
                : "berada sebelum periode paling awal {$earliest?->code()} di kalender";

            return [
                'year' => $year,
                'created' => $created,
                'created_open' => $createdOpen,
                'created_closed' => $createdClosed,
                'existing' => $existing,
                'created_status' => match (true) {
                    $created === 0 => 'none',
                    $createdClosed === 0 => 'open',
                    $createdOpen === 0 => 'closed',
                    default => 'mixed',
                },
                'message' => match (true) {
                    $created === 0 => "Kalender {$year} sudah lengkap — 12 periode sudah ada.",
                    $createdClosed === 0 => "Kalender fiskal {$year} dibuat — {$created} periode terbuka.",
                    $createdOpen === 0 => "Kalender {$year} dibuat: {$created} periode, semuanya DITUTUP karena {$because}.",
                    default => "Kalender {$year} dibuat: {$createdOpen} periode terbuka, {$createdClosed} periode "
                        ."ditutup karena {$because}.",
                },
            ];
        });
    }

    /**
     * Periods that ended more than $days ago and are still open, oldest first.
     *
     * @return Collection<int, FiscalPeriod>
     */
    public function overdue(int $days): Collection
    {
        $cutoff = CarbonImmutable::today()->subDays(max(0, $days));

        return FiscalPeriod::query()
            ->where('status', PeriodStatus::Open->value)
            ->orderBy('year')->orderBy('month')
            ->get()
            ->filter(fn (FiscalPeriod $period): bool => $period->periodEnd() < $cutoff->toDateString())
            ->values();
    }

    // ---------------------------------------------------------------- shared

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function failing(array $items, string $severity): array
    {
        return array_values(array_filter(
            $items,
            fn (array $item): bool => $item['severity'] === $severity && $item['status'] === self::FAIL,
        ));
    }

    private function itemByKey(array $items, string $key): array
    {
        foreach ($items as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return ['key' => $key, 'short' => $key];
    }

    private function item(
        string $key,
        string $label,
        string $severity,
        string $status,
        string $detail,
        string $short,
        ?string $link = null,
        int $count = 0,
        array $extra = [],
    ): array {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'severity' => $severity,
            'status' => $status,
            'count' => $count,
            'detail' => $detail,
            // A compressed phrase for the refusal message and the button label;
            // the screen shows `detail`, which carries the real numbers.
            'short' => $short,
            'link' => $link,
        ], $extra);
    }

    private function recordEvent(
        FiscalPeriod $period,
        PeriodEventAction $action,
        User $by,
        ?string $note,
        array $overrides,
        array $checklist,
    ): PeriodEvent {
        return PeriodEvent::query()->create([
            'fiscal_period_id' => $period->id,
            'action' => $action,
            'user_id' => $by->id,
            'note' => $note,
            'overrides' => $overrides,
            'checklist' => $checklist,
        ]);
    }
}
