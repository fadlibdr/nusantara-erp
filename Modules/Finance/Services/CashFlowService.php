<?php

namespace Modules\Finance\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Crm\Services\TerminBillingService;
use Modules\Finance\Enums\PaymentDirection;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Models\PettyCashFund;
use Modules\Finance\Support\CashFlowActivityMap;
use Modules\Finance\Support\OutstandingAsOf;
use Modules\Finance\Support\SettleableLiabilities;
use Modules\Finance\Support\TaxDeadlines;

/**
 * Arus kas — the answer to audit A3 "Tidak ada arus kas". Read-only over
 * existing tables (posted journals, open AR/AP, unposted payments, payroll
 * runs, termin schedules): no migration, no new document type, and therefore
 * no DanglingDocuments registry entry — recorded here so the next package
 * does not re-litigate it.
 *
 * HISTORICAL STATEMENT (PSAK 2, direct method). For every posted journal that
 * touches the cash pool, each NON-cash line contributes (credit − debit) to
 * cash. A balanced journal guarantees the counter lines sum exactly to the
 * pool movement, so multi-line journals decompose without proration: the wapu
 * receipt (Dr Bank 982,5 jt + Dr 1-1700 17,5 jt + Dr 2-1300 110 jt /
 * Cr 1-1300 1.110 jt) reads as a gross Rp 1,11 M customer receipt in operasi
 * minus its two tax slices, which is what a direct-method statement is FOR.
 * Journals whose lines are ALL in the pool are 'mutasi antar rekening' — an
 * info figure outside the activities, because moving Rp 1 M from Mandiri to
 * BCA is not cash flow. Counter accounts classify through
 * CashFlowActivityMap; unmapped codes land in a VISIBLE 'lainnya' activity
 * carrying sample journal codes, and the 'reconciled' flag recomputes
 * opening + activities = closing from independent GL sums, so silently
 * dropping a line is arithmetically impossible to hide.
 *
 * KNOWN LIMITATION: a reversal pair (JournalService::reverseFor) that touched
 * the bank inflates GROSS inflow and outflow of its activity symmetrically —
 * net per activity, net change and the reconciliation stay exact.
 *
 * PROJECTION (90 hari). Opening = pool GL today — the demo's BCA Operasional
 * prints its honest −232.545.000 (PAY/2026/IV/0001 debited a bank whose
 * opening balance was never journalled) with a warning, never clamped.
 * Weekly buckets carry AR/AP by due date, billing-ready termins, the latest
 * approved regular payroll recurring monthly, statutory tax deadlines, and
 * approved-but-unposted disbursements. Every estimate is an Indonesian
 * sentence in the 'assumptions' array WITH its real numbers — a projection
 * whose assumptions are invisible is a lie, so they are payload, not
 * documentation. The deliberate asymmetry: overdue AR sits in 'Lewat jatuh
 * tempo' and never enters the running balance (when it arrives is unknown),
 * while overdue AP is charged to week 1 — obligations don't evaporate.
 *
 * KAS KECIL, which landed after this report was designed, is inside the pool
 * (drawer leaves are postable 1-11xx): a replenishment PAY moves bank → laci
 * WITHIN the pool, so fund-stamped payments are excluded from projected
 * outflows, and drawer top-up needs plus outstanding kasbon are reported as
 * an info block instead of being double-charged.
 */
class CashFlowService
{
    public function __construct(
        private readonly PettyCashFundService $pettyCashFunds,
    ) {}

    // ------------------------------------------------------------ statement

    /**
     * PSAK 2 direct-method statement between two dates (inclusive).
     */
    public function statement(string $from, string $to): array
    {
        $fromDate = CarbonImmutable::parse($from)->startOfDay();
        $toDate = CarbonImmutable::parse($to)->startOfDay();

        if ($fromDate->greaterThan($toDate)) {
            throw new LogicException("Statement 'from' date must not be after 'to'.");
        }

        $pool = CashFlowActivityMap::cashAccountIds();

        $openingByAccount = $this->poolBalances($pool, before: $fromDate->toDateString());
        $closingByAccount = $this->poolBalances($pool, through: $toDate->toDateString());

        // Every posted journal touching the pool inside the window, with ALL
        // its lines — the counter lines are the classification input.
        $journalIds = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            // whereDate, the DanglingDocuments lesson: journal_date is cast
            // `date` and stored "…-06-30 00:00:00", which raw string bounds
            // drop on the last day of the month.
            ->whereDate('fin_journals.journal_date', '>=', $fromDate->toDateString())
            ->whereDate('fin_journals.journal_date', '<=', $toDate->toDateString())
            ->whereIn('fin_journal_lines.account_id', $pool)
            ->distinct()
            ->pluck('fin_journals.id');

        $lines = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            // DB::table on purpose: a soft-deleted ACCOUNT must keep
            // classifying its history, and the query builder applies no scope.
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->whereIn('fin_journal_lines.journal_id', $journalIds)
            ->orderBy('fin_journal_lines.id')
            ->get([
                'fin_journal_lines.journal_id',
                'fin_journals.code as journal_code',
                'fin_journal_lines.account_id',
                'fin_accounts.code as account_code',
                'fin_accounts.name as account_name',
                'fin_journal_lines.debit',
                'fin_journal_lines.credit',
            ]);

        $poolIds = array_flip($pool);
        $byAccount = [];
        $internalTransfers = 0.0;

        foreach ($lines->groupBy('journal_id') as $journalLines) {
            $counterLines = $journalLines->reject(
                fn (object $line): bool => isset($poolIds[(int) $line->account_id])
            );

            if ($counterLines->isEmpty()) {
                // Pure pool-to-pool journal (bank transfer, drawer top-up):
                // total cash unchanged, reported as information only. A MIXED
                // transfer+admin-fee journal never reaches this branch — its
                // 7-2100 line is a counter line and classifies alone.
                $internalTransfers = round(
                    $internalTransfers + (float) $journalLines->sum('debit'),
                    2,
                );

                continue;
            }

            foreach ($counterLines as $line) {
                // The counter lines of a balanced journal sum exactly to the
                // pool movement: credit − debit is this line's cash effect.
                $contribution = round((float) $line->credit - (float) $line->debit, 2);

                if ($contribution === 0.0) {
                    continue;
                }

                $code = (string) $line->account_code;
                $byAccount[$code] ??= [
                    'account_code' => $code,
                    'account_name' => (string) $line->account_name,
                    'inflow' => 0.0,
                    'outflow' => 0.0,
                    'journal_codes' => [],
                ];

                if ($contribution > 0) {
                    $byAccount[$code]['inflow'] = round($byAccount[$code]['inflow'] + $contribution, 2);
                } else {
                    $byAccount[$code]['outflow'] = round($byAccount[$code]['outflow'] - $contribution, 2);
                }

                // Up to 5 sample journals per account, so a 'lainnya' row is a
                // lead an accountant can chase, not a dead number.
                if (count($byAccount[$code]['journal_codes']) < 5
                    && ! in_array($line->journal_code, $byAccount[$code]['journal_codes'], true)) {
                    $byAccount[$code]['journal_codes'][] = (string) $line->journal_code;
                }
            }
        }

        ksort($byAccount);

        $activities = [
            'operasi' => ['rows' => [], 'total' => 0.0],
            'investasi' => ['rows' => [], 'total' => 0.0],
            'pendanaan' => ['rows' => [], 'total' => 0.0],
            'lainnya' => ['rows' => [], 'total' => 0.0],
        ];

        foreach ($byAccount as $row) {
            $activity = CashFlowActivityMap::activityFor($row['account_code']) ?? 'lainnya';
            $net = round($row['inflow'] - $row['outflow'], 2);

            $out = [
                'account_code' => $row['account_code'],
                'account_name' => $row['account_name'],
                'inflow' => $row['inflow'],
                'outflow' => $row['outflow'],
                'net' => $net,
            ];

            // Only the escape hatch carries its evidence; mapped rows have a
            // known meaning and stay lean.
            if ($activity === 'lainnya') {
                $out['journal_codes'] = $row['journal_codes'];
            }

            $activities[$activity]['rows'][] = $out;
            $activities[$activity]['total'] = round($activities[$activity]['total'] + $net, 2);
        }

        $opening = round(array_sum(array_column($openingByAccount, 'balance')), 2);
        $closing = round(array_sum(array_column($closingByAccount, 'balance')), 2);
        $netChange = round(
            $activities['operasi']['total'] + $activities['investasi']['total']
            + $activities['pendanaan']['total'] + $activities['lainnya']['total'],
            2,
        );

        $accounts = [];

        foreach ($closingByAccount as $accountId => $close) {
            $open = $openingByAccount[$accountId]['balance'] ?? 0.0;

            if ($open === 0.0 && $close['balance'] === 0.0) {
                continue; // a never-touched pool account adds nothing but noise
            }

            $accounts[] = [
                'account_code' => $close['code'],
                'account_name' => $close['name'],
                'opening' => $open,
                'closing' => $close['balance'],
            ];
        }

        usort($accounts, fn (array $a, array $b): int => strcmp($a['account_code'], $b['account_code']));

        return [
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'accounts' => $accounts,
            'activities' => $activities,
            'internal_transfers' => $internalTransfers,
            'net_change' => $netChange,
            // Recomputed from INDEPENDENT sums (pool balances vs counter-line
            // contributions), same idea as trialBalance's 'balanced': a
            // dropped or double-counted line cannot hide.
            'reconciled' => abs((int) round(($opening + $netChange - $closing) * 100)) <= 1,
            // The two PSAK 2 electives, printed: both presentations are
            // permitted, so the chosen one must be on the report itself.
            'policy' => [
                'Bunga diterima (7-11xx) disajikan sebagai aktivitas operasi.',
                'Bunga pinjaman dibayar (7-2200) disajikan sebagai aktivitas pendanaan.',
            ],
        ];
    }

    // ----------------------------------------------------------- projection

    /**
     * Cash projection over the coming weeks (default 90 days, clamped 7..180).
     */
    public function projection(int $days = 90): array
    {
        $days = max(7, min(180, $days));
        $today = CarbonImmutable::today();
        $horizonEnd = $today->addDays($days - 1); // inclusive last projected day
        $weekCount = (int) ceil($days / 7);

        $pool = CashFlowActivityMap::cashAccountIds();
        $openingByAccount = $this->poolBalances($pool, through: $today->toDateString());

        $openingAccounts = [];
        $warnings = [];

        foreach ($openingByAccount as $row) {
            if ($row['balance'] === 0.0) {
                continue;
            }

            $openingAccounts[] = ['code' => $row['code'], 'name' => $row['name'], 'balance' => $row['balance']];

            if ($row['balance'] < 0.0) {
                // The demo's BCA Operasional: −232.545.000 because
                // PAY/2026/IV/0001 debited a bank that never received an
                // opening-balance journal. Printed, never clamped — clamping
                // would make the projection lie about its own starting point.
                $warnings[] = "Saldo GL {$row['name']} negatif ({$this->rp($row['balance'])}) — "
                    .'saldo awal kemungkinan belum dijurnal.';
            }
        }

        usort($openingAccounts, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
        $openingTotal = round(array_sum(array_column($openingByAccount, 'balance')), 2);

        $weeks = [];

        for ($index = 0; $index < $weekCount; $index++) {
            $weekFrom = $today->addDays($index * 7);
            $weekTo = $weekFrom->addDays(6)->min($horizonEnd);

            $weeks[] = [
                'key' => $weekFrom->toDateString(),
                'label' => $this->weekLabel($weekFrom, $weekTo),
                'from' => $weekFrom->toDateString(),
                'to' => $weekTo->toDateString(),
                'inflow_ar' => 0.0,
                'inflow_termin' => 0.0,
                'outflow_ap' => 0.0,
                'outflow_payroll' => 0.0,
                'outflow_tax' => 0.0,
                'outflow_payments_approved' => 0.0,
            ];
        }

        $weekIndexFor = function (CarbonImmutable $date) use ($today, $horizonEnd): ?int {
            if ($date->lessThan($today) || $date->greaterThan($horizonEnd)) {
                return null;
            }

            return intdiv((int) $today->diffInDays($date), 7);
        };

        $overdue = [
            'ar' => ['count' => 0, 'total' => 0.0, 'oldest_days' => 0, 'rows' => []],
            'ap' => ['count' => 0, 'total' => 0.0, 'rows' => []],
        ];

        // ---- Masuk: piutang termin yang sudah difakturkan, pada jatuh tempo.
        //
        // Sisa piutang PER HARI INI, bukan amount_paid seumur hidup. The
        // opening balance is the cash pool through today, so a receipt DATED
        // ahead of today is money that has not arrived: netting it off the
        // receivable deleted Rp 300.000.000 from the projection outright —
        // absent from the opening balance because poolBalances only reads
        // through today, and absent from the AR lane because amount_paid had
        // already moved. Same basis as ReportService::agingReport and
        // PeriodCloseService::subledgerOutstanding.
        $arInvoices = ArInvoice::query()
            ->with('customer')
            ->where('status', DocumentStatus::Approved->value)
            ->orderBy('due_date')
            ->get();

        // Documents are NOT date-bounded here, unlike the aging report: a
        // forward-dated approved invoice is cash this horizon really expects,
        // and the projection answers "cukupkah kas", not "what did the control
        // account hold on a date".
        $arSettled = OutstandingAsOf::settled(
            PaymentAllocation::TYPE_AR_INVOICE,
            $arInvoices->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $today->toDateString(),
        );

        foreach ($arInvoices as $invoice) {
            $outstanding = round((float) $invoice->total - ($arSettled[(int) $invoice->id] ?? 0.0), 2);

            if ($outstanding <= 0) {
                continue;
            }

            $due = CarbonImmutable::parse($invoice->due_date)->startOfDay();

            if ($due->lessThan($today)) {
                // Kapan piutang telat benar-benar cair tidak diketahui — masuk
                // keranjang 'Lewat jatuh tempo' dan TIDAK pernah menyentuh
                // saldo berjalan (kehati-hatian yang dicetak sebagai asumsi).
                $daysOverdue = (int) $due->diffInDays($today);
                $overdue['ar']['count']++;
                $overdue['ar']['total'] = round($overdue['ar']['total'] + $outstanding, 2);
                $overdue['ar']['oldest_days'] = max($overdue['ar']['oldest_days'], $daysOverdue);
                $overdue['ar']['rows'][] = [
                    'code' => $invoice->code,
                    'partner' => $invoice->customer?->name,
                    'due_date' => $due->toDateString(),
                    'outstanding' => $outstanding,
                    'days_overdue' => $daysOverdue,
                ];

                continue;
            }

            $week = $weekIndexFor($due);

            if ($week !== null) {
                $weeks[$week]['inflow_ar'] = round($weeks[$week]['inflow_ar'] + $outstanding, 2);
            }
        }

        // ---- Masuk: termin siap tagih / terjadwal, lewat lag penagihan.
        [$terminAssumption] = $this->projectTermins($weeks, $weekIndexFor, $today, $horizonEnd);

        // ---- Keluar: pembayaran yang sudah diajukan/disetujui tapi belum
        // diposting — dan alokasinya, penjaga hitung-ganda terhadap lane AP
        // dan lane pajak di bawah.
        $pendingPayments = Payment::query()
            ->with('allocations')
            ->where('direction', PaymentDirection::Out)
            ->whereIn('status', [PaymentStatus::Submitted->value, PaymentStatus::Approved->value])
            ->get();

        $pendingByBill = [];

        foreach ($pendingPayments as $payment) {
            // Isi ulang kas kecil pindah bank → laci DI DALAM pool kas: total
            // kas tidak berubah, jadi tidak boleh dibebankan sebagai arus
            // keluar. Kebutuhannya tetap terlihat di blok kas_kecil di bawah.
            if ($payment->petty_cash_fund_id !== null) {
                continue;
            }

            foreach ($payment->allocations as $allocation) {
                if ($allocation->payable_type === PaymentAllocation::TYPE_AP_BILL) {
                    $billId = (int) $allocation->payable_id;
                    $pendingByBill[$billId] = round(
                        ($pendingByBill[$billId] ?? 0.0) + (float) $allocation->amount,
                        2,
                    );
                }
            }

            $paymentDate = CarbonImmutable::parse($payment->payment_date)->startOfDay();
            // Disbursement dated in the past but not yet posted: the
            // obligation has not evaporated — charge it to week 1.
            $week = $weekIndexFor($paymentDate->max($today));

            if ($week !== null) {
                $weeks[$week]['outflow_payments_approved'] = round(
                    $weeks[$week]['outflow_payments_approved'] + (float) $payment->amount,
                    2,
                );
            }
        }

        // ---- Keluar: hutang vendor pada jatuh tempo, dikurangi alokasi yang
        // sudah menunggu di pembayaran belum diposting (satu rupiah satu lane).
        $apBills = ApBill::query()
            ->with('vendor')
            ->where('status', DocumentStatus::Approved->value)
            ->orderBy('due_date')
            ->get();

        // Symmetrical with the AR lane, and it matters in the dangerous
        // direction: a disbursement posted today but DATED next month has
        // already left the payable behind on the amount_paid basis while its
        // cash is still sitting in the opening pool, so the projection showed
        // money the company had committed as money it could still spend.
        $apSettled = OutstandingAsOf::settled(
            PaymentAllocation::TYPE_AP_BILL,
            $apBills->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $today->toDateString(),
        );

        foreach ($apBills as $bill) {
            $outstanding = round((float) $bill->total_payable - ($apSettled[(int) $bill->id] ?? 0.0), 2);
            $projected = round(max(0.0, $outstanding - ($pendingByBill[$bill->id] ?? 0.0)), 2);

            if ($projected <= 0) {
                continue;
            }

            $due = CarbonImmutable::parse($bill->due_date)->startOfDay();

            if ($due->lessThan($today)) {
                $daysOverdue = (int) $due->diffInDays($today);
                $overdue['ap']['count']++;
                $overdue['ap']['total'] = round($overdue['ap']['total'] + $projected, 2);
                $overdue['ap']['rows'][] = [
                    'code' => $bill->code,
                    'partner' => $bill->vendor?->name,
                    'due_date' => $due->toDateString(),
                    'outstanding' => $projected,
                    'days_overdue' => $daysOverdue,
                ];

                // Asimetri yang disengaja: hutang telat DIBEBANKAN ke minggu
                // pertama — kewajiban tidak menguap hanya karena telat.
                $weeks[0]['outflow_ap'] = round($weeks[0]['outflow_ap'] + $projected, 2);

                continue;
            }

            $week = $weekIndexFor($due);

            if ($week !== null) {
                $weeks[$week]['outflow_ap'] = round($weeks[$week]['outflow_ap'] + $projected, 2);
            }
        }

        // ---- Keluar: gaji — run reguler terakhir yang disetujui, berulang
        // bulanan pada tanggal bayarnya.
        $payrollAssumption = $this->projectPayroll($weeks, $weekIndexFor, $today, $horizonEnd);

        // ---- Keluar: setoran pajak pada tenggat resminya.
        $taxAssumption = $this->projectTaxes($weeks, $weekIndexFor, $today);

        // ---- Kas kecil: informasi, bukan arus (lihat docblock kelas).
        $kasKecil = $this->kasKecilExposure();

        $running = $openingTotal;
        $lowest = null;

        foreach ($weeks as $index => $week) {
            $net = round(
                $week['inflow_ar'] + $week['inflow_termin']
                - $week['outflow_ap'] - $week['outflow_payroll']
                - $week['outflow_tax'] - $week['outflow_payments_approved'],
                2,
            );
            $running = round($running + $net, 2);

            $weeks[$index]['net'] = $net;
            $weeks[$index]['running_balance'] = $running;

            if ($lowest === null || $running < $lowest['balance']) {
                $lowest = ['key' => $week['key'], 'label' => $week['label'], 'balance' => $running];
            }

            if ($running < 0 && ! $this->hasDeficitWarning($warnings)) {
                $warnings[] = "Saldo kas diproyeksikan NEGATIF mulai minggu {$week['label']} "
                    ."({$this->rp($running)}).";
            }
        }

        $buckets = array_merge([[
            'key' => 'overdue',
            'label' => 'Lewat jatuh tempo',
            'inflow_ar' => $overdue['ar']['total'],
            'outflow_ap' => $overdue['ap']['total'],
            'in_running_balance' => false,
        ]], $weeks);

        $assumptions = array_values(array_filter([
            'Piutang jatuh tempo dianggap diterima pada tanggal jatuh temponya; yang sudah lewat tempo '
            ."({$overdue['ar']['count']} dokumen, {$this->rp($overdue['ar']['total'])}) ditampilkan "
            .'terpisah dan TIDAK dihitung dalam saldo berjalan.',
            "Hutang lewat tempo ({$overdue['ap']['count']} dokumen, {$this->rp($overdue['ap']['total'])}) "
            .'dianggap dibayar minggu ini.',
            $terminAssumption,
            $payrollAssumption,
            $taxAssumption,
            'Pembayaran keluar yang sudah diajukan/disetujui tetapi belum diposting dibebankan pada '
            .'tanggal pembayarannya; alokasinya mengurangi proyeksi tagihan, gaji, dan pajak yang '
            .'sama agar tidak dihitung dua kali.',
            $kasKecil === null ? null
                : 'Isi ulang kas kecil adalah perpindahan di dalam kas (bank ke laci) dan tidak mengubah '
                ."totalnya; kebutuhan isi ulang ({$this->rp($kasKecil['replenishment_due_total'])}) dan "
                ."kasbon berjalan ({$this->rp($kasKecil['outstanding_kasbon_total'])}) ditampilkan "
                .'sebagai informasi, bukan arus keluar.',
        ]));

        return [
            'as_of' => $today->toDateString(),
            'days' => $days,
            'opening' => ['total' => $openingTotal, 'accounts' => $openingAccounts],
            'overdue' => $overdue,
            'buckets' => $buckets,
            'lowest' => $lowest,
            'ending_balance' => $running,
            'kas_kecil' => $kasKecil,
            'assumptions' => $assumptions,
            'warnings' => $warnings,
        ];
    }

    // -------------------------------------------------------- bank balances

    /**
     * Saldo GL per rekening bank + kas untuk dashboard: a PLAIN ARRAY on
     * purpose, because dashboard.js consumes list endpoints through safe()
     * and reduces client-side — the tile itself is the dashboard owners'
     * six-line seam.
     *
     * The kas rows are every postable 1-11% account (Kas where it is still a
     * leaf, plus each kas kecil drawer). On installations where migration
     * 2026_08_01_001109 flipped 1-1100 to a group — the live demo — the drawer
     * leaves ARE the kas rows and no synthetic 1-1100 row exists.
     *
     * DEACTIVATION HIDES AN ACCOUNT ONLY ONCE IT IS EMPTY. Excluding inactive
     * rekening is deliberate and pinned (BankBalancesApiTest::
     * test_an_inactive_bank_account_is_excluded) — a wound-down account should
     * not clutter the tile forever. But that test never posts a journal to the
     * account it deactivates, so "inactive banks stay out" had only ever been
     * asserted for an EMPTY one, and nothing distinguished the two cases:
     * marking BANK-MDR-PRJ inactive while Rp 11.352.000.000 was still sitting
     * in it dropped the dashboard cash tile to −Rp 331.045.000 — a company
     * apparently out of cash — while the balance sheet, the PSAK 2 statement
     * and CashFlowActivityMap::cashAccountIds (which has never filtered on
     * is_active, nor on soft deletion) all still read Rp 11.020.955.000.
     * A flag on a master record must not be able to move the cash total. The
     * balance decides; is_active only decides whether an EMPTIED account is
     * still worth a row, and the row carries the flag so the tile can say so.
     */
    public function bankBalances(): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $rows = [];
        $seenAccountIds = [];

        // withTrashed for the same reason: cashAccountIds() counts a
        // soft-deleted rekening's money because the GL still holds it.
        $bankAccounts = BankAccount::query()
            ->with('coaAccount')
            ->withTrashed()
            ->orderBy('code')
            ->get();

        foreach ($bankAccounts as $bank) {
            $balance = $this->accountBalance((int) $bank->coa_account_id, $today);
            $isActive = (bool) $bank->is_active && $bank->deleted_at === null;

            if (! $isActive && $balance === 0.0) {
                continue;
            }

            $seenAccountIds[] = (int) $bank->coa_account_id;

            $rows[] = [
                'bank_account_id' => (int) $bank->id,
                'code' => $bank->code,
                'name' => $bank->name,
                'bank_name' => $bank->bank_name,
                'account_no' => $bank->account_no,
                'coa_code' => $bank->coaAccount?->code,
                'balance' => $balance,
                'is_active' => $isActive,
                'as_of' => $today,
            ];
        }

        $kasAccounts = Account::query()
            ->where('is_postable', true)
            ->where('code', 'like', '1-11%')
            ->whereNotIn('id', $seenAccountIds)
            ->orderBy('code')
            ->get();

        foreach ($kasAccounts as $account) {
            $balance = $this->accountBalance((int) $account->id, $today);

            if (! $account->is_active && $balance === 0.0) {
                continue;
            }

            $rows[] = [
                'bank_account_id' => null,
                'code' => 'KAS',
                'name' => $account->name,
                'bank_name' => null,
                'account_no' => null,
                'coa_code' => $account->code,
                'balance' => $balance,
                'is_active' => (bool) $account->is_active,
                'as_of' => $today,
            ];
        }

        return $rows;
    }

    // ------------------------------------------------- projection: termins

    /**
     * Termin inflows: billing-ready termins assumed invoiced today and
     * collected after the configured lag, grossed with PPN — demo evidence
     * for the grossing: termin DP Rp 9,7 M was billed as INV/2026/II/0001
     * totalling Rp 10,767 M. Plus termins whose due_date falls inside the
     * horizon (not yet ready today), collected at due_date + the same lag.
     * billed_at IS NULL on both lanes and billing-ready ids are excluded from
     * the scheduled lane, so nothing is counted twice against AR.
     *
     * class_exists-guarded (ReportService's Estimation pattern): if the Crm
     * module vanishes the termin lane degrades to empty instead of a 500.
     *
     * @param  array<int, array<string, mixed>>  $weeks  mutated in place
     * @return array{0: ?string} the assumption sentence (null when Crm absent)
     */
    private function projectTermins(array &$weeks, callable $weekIndexFor, CarbonImmutable $today, CarbonImmutable $horizonEnd): array
    {
        if (! class_exists(TerminBillingService::class)) {
            return [null];
        }

        $lag = Erp::int('cashflow.termin_collection_days', 30);
        $ppnRate = (float) (DB::table('fin_taxes')->where('code', 'PPN')->whereNull('deleted_at')->value('rate') ?? 11.0);
        $gross = fn (float $amount): float => round($amount * (1 + $ppnRate / 100), 2);

        $ready = app(TerminBillingService::class)->billingReady($today->toDateString());
        $readyIds = array_map(fn (array $row): int => (int) $row['termin_id'], $ready);
        $readyTotal = 0.0;

        $collectionDate = $today->addDays($lag);
        $readyWeek = $weekIndexFor($collectionDate);

        foreach ($ready as $row) {
            $amount = $gross((float) $row['amount']);
            $readyTotal = round($readyTotal + $amount, 2);

            if ($readyWeek !== null) {
                $weeks[$readyWeek]['inflow_termin'] = round($weeks[$readyWeek]['inflow_termin'] + $amount, 2);
            }
        }

        // Terjadwal: due_date di dalam horizon tapi belum jatuh hari ini —
        // DB::table like TerminBillingService::achievedMilestones(), so
        // Finance does not depend on the Crm MODELS at runtime.
        $scheduled = DB::table('crm_contract_termins')
            ->join('crm_contracts', 'crm_contracts.id', '=', 'crm_contract_termins.contract_id')
            ->whereNull('crm_contracts.deleted_at')
            ->where('crm_contracts.status', DocumentStatus::Approved->value)
            ->whereNull('crm_contract_termins.billed_at')
            ->whereNotIn('crm_contract_termins.id', $readyIds)
            ->whereNotNull('crm_contract_termins.due_date')
            ->whereDate('crm_contract_termins.due_date', '>', $today->toDateString())
            ->whereDate('crm_contract_termins.due_date', '<=', $horizonEnd->toDateString())
            ->get(['crm_contract_termins.amount', 'crm_contract_termins.due_date']);

        foreach ($scheduled as $termin) {
            $week = $weekIndexFor(CarbonImmutable::parse($termin->due_date)->startOfDay()->addDays($lag));

            if ($week !== null) {
                $weeks[$week]['inflow_termin'] = round($weeks[$week]['inflow_termin'] + $gross((float) $termin->amount), 2);
            }
        }

        return [
            'Termin siap tagih ('.count($ready)." termin, {$this->rp($readyTotal)} termasuk PPN {$ppnRate}%) "
            ."dianggap ditagih hari ini dan diterima {$lag} hari kemudian "
            ."(cashflow.termin_collection_days = {$lag}); termin terjadwal dalam horizon dihitung "
            .'pada jatuh temponya ditambah lag yang sama.',
        ];
    }

    // ------------------------------------------------- projection: payroll

    /**
     * The latest APPROVED run_type='regular' run (demo: PYR/2026/06/002,
     * Rp 166.638.981,43 paid the 25th) recurring monthly on its payment_date
     * day-of-month. THR runs are excluded from the basis on purpose — using
     * one would project a thirteenth salary every month.
     *
     * The EARLIEST occurrence is reduced by gl_account allocations on
     * 2-1110/2-1120 already riding submitted/approved payments — those ride
     * outflow_payments_approved, and without the deduction the month whose
     * salary payment is already submitted (accrual posted month-end, PAY
     * submitted before the 25th payday — the exact flow the month-end ceiling
     * exists to allow) was charged twice: Rp 166.638.981,43 in
     * outflow_payroll AND Rp 166.638.981,43 in outflow_payments_approved for
     * one salary, understating the running balance by a full month's wages.
     *
     * The flip side is accepted and recorded: a POSTED prior-month accrual
     * with no payment drafted yet sits in 2-1110 invisible to every lane (no
     * balance lane reads 2-1110). The recurrence approximates it at the next
     * payday; a dedicated 2-1110-balance lane would double it against that
     * same recurrence.
     *
     * @param  array<int, array<string, mixed>>  $weeks  mutated in place
     */
    private function projectPayroll(array &$weeks, callable $weekIndexFor, CarbonImmutable $today, CarbonImmutable $horizonEnd): string
    {
        $run = DB::table('hr_payroll_runs')
            ->where('run_type', 'regular')
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('deleted_at')
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->first();

        if ($run === null) {
            return 'Belum ada run payroll reguler yang disetujui, jadi tidak ada proyeksi gaji.';
        }

        $amount = round((float) $run->total_net, 2);
        $payDay = (int) CarbonImmutable::parse($run->payment_date)->format('j');

        $pending = 0.0;

        foreach (['2-1110', '2-1120'] as $code) {
            $account = Account::query()->withTrashed()->where('code', $code)->first();

            if ($account !== null) {
                $pending = round($pending + SettleableLiabilities::pendingAllocations((int) $account->id), 2);
            }
        }

        $isEarliest = true;

        for ($month = $today->startOfMonth(); $month->lessThanOrEqualTo($horizonEnd); $month = $month->addMonth()) {
            // Tanggal 31 pada bulan 30-hari jatuh di hari terakhir bulan itu.
            $payDate = $month->day(min($payDay, $month->daysInMonth));
            $week = $weekIndexFor($payDate);

            if ($week !== null) {
                // Satu gaji satu lane: klaim 2-1110/2-1120 pada pembayaran
                // belum diposting mengurangi kejadian gaji TERDEKAT saja —
                // bulan berikutnya kembali ke estimasi penuh.
                $charge = $isEarliest ? max(0.0, round($amount - $pending, 2)) : $amount;
                $isEarliest = false;

                $weeks[$week]['outflow_payroll'] = round($weeks[$week]['outflow_payroll'] + $charge, 2);
            }
        }

        return "Gaji diperkirakan dari run reguler terakhir yang disetujui ({$run->code}, "
            ."{$this->rp($amount)}) berulang tiap tanggal {$payDay}; run THR tidak dipakai sebagai basis."
            .($pending > 0.0
                ? " Gaji terdekat dikurangi {$this->rp($pending)} yang sudah menunggu pada pembayaran "
                .'2-1110/2-1120 belum diposting.'
                : '');
    }

    // --------------------------------------------------- projection: taxes

    /**
     * Statutory deadlines, one payment per payable account, at the NEAREST
     * deadline not yet passed — because a balance read "through today" is
     * dominated by PRIOR masas, not the current one.
     *
     * PPh balances (2-1210/2-1220/2-1230/2-1240) are due the 10th: run the
     * projection on the 2nd and last month's withholdings are due THIS
     * month's 10th, days away — pushing them to next month's 10th (the old
     * addMonth()) deferred them a full month past their legal deadline. Past
     * the 10th, the prior masa's deadline has passed and the balance rolls to
     * the next 10th. Net PPN (saldo 2-1300 − saldo 1-1600; demo per
     * 2026-08-02: 1.067.000.000 − 23.045.000 = 1.043.955.000, all masa Juli)
     * is due the END OF THE CURRENT MONTH (2026-08-31) — the old addMonth()
     * charged it to the 2026-09-27 week and overstated four weekly running
     * balances by the full Rp 1.043.955.000, exactly where the "lowest point"
     * decision lives. The current masa's sliver rides the same earlier date
     * and rolls forward at the next run — the service's own "obligations
     * don't evaporate" asymmetry argues for the earlier date, never the
     * later.
     *
     * Balances are posted GL through today MINUS gl_account allocations
     * already riding unposted payments — those are charged on the payments
     * lane, and one liability must not be projected twice. The per-masa view
     * lives in the kalender pajak register (TaxObligationService), which reads
     * its due dates from the SAME TaxDeadlines rules consumed here — the
     * balances stay coarse on purpose, and that coarseness is exactly why the
     * sentence is printed.
     *
     * @param  array<int, array<string, mixed>>  $weeks  mutated in place
     */
    private function projectTaxes(array &$weeks, callable $weekIndexFor, CarbonImmutable $today): string
    {
        // Tenggat tanggal 10 terdekat yang belum lewat, dan PPN masa lalu
        // jatuh tempo akhir bulan BERJALAN — both rules defined once in
        // TaxDeadlines, shared with the kalender pajak register.
        $pphDue = TaxDeadlines::nearestPphDue($today);
        $ppnDue = TaxDeadlines::nearestPpnDue($today);

        $pphWeek = $weekIndexFor($pphDue);

        foreach (['2-1210', '2-1220', '2-1230', '2-1240'] as $code) {
            $balance = $this->liabilityExposure($code, $today->toDateString());

            if ($balance > 0 && $pphWeek !== null) {
                $weeks[$pphWeek]['outflow_tax'] = round($weeks[$pphWeek]['outflow_tax'] + $balance, 2);
            }
        }

        $ppnOut = $this->liabilityExposure('2-1300', $today->toDateString());
        // 1-1600 PPN Masukan is an asset: its DEBIT balance is the credit we
        // may offset, so the signed credit−debit reading comes back negative
        // and is subtracted (minus a negative) here.
        $ppnIn = -$this->signedCreditBalance('1-1600', $today->toDateString());
        $ppnNet = round(max(0.0, $ppnOut - max(0.0, $ppnIn)), 2);
        $ppnWeek = $weekIndexFor($ppnDue);

        if ($ppnNet > 0 && $ppnWeek !== null) {
            $weeks[$ppnWeek]['outflow_tax'] = round($weeks[$ppnWeek]['outflow_tax'] + $ppnNet, 2);
        }

        return 'Saldo hutang pajak didominasi masa yang sudah berjalan, jadi disetor pada tenggat '
            ."terdekat yang belum lewat: PPh tanggal 10 ({$pphDue->toDateString()}), PPN neto "
            ."(Keluaran dikurangi Masukan = {$this->rp($ppnNet)}) akhir bulan berjalan "
            ."({$ppnDue->toDateString()}). Belum ada pemisahan per masa pajak — saldo masa berjalan "
            .'ikut tenggat yang sama (lebih awal, bukan lebih lambat).';
    }

    /**
     * Posted credit balance of a liability code through a date, minus what
     * unposted payments have already allocated against the same account.
     */
    private function liabilityExposure(string $code, string $through): float
    {
        $account = Account::query()->withTrashed()->where('code', $code)->first();

        if ($account === null) {
            return 0.0;
        }

        return round(
            max(0.0, $this->signedCreditBalance($code, $through)
                - SettleableLiabilities::pendingAllocations((int) $account->id)),
            2,
        );
    }

    /** Posted (credit − debit) of one account code through a date. */
    private function signedCreditBalance(string $code, string $through): float
    {
        $sums = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_accounts.code', $code)
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            ->whereDate('fin_journals.journal_date', '<=', $through)
            ->selectRaw('COALESCE(SUM(credit), 0) as c, COALESCE(SUM(debit), 0) as d')
            ->first();

        return round((float) $sums->c - (float) $sums->d, 2);
    }

    // ------------------------------------------------ projection: kas kecil

    /**
     * Drawer top-up needs and outstanding kasbon per active fund —
     * INFORMATION, never charged to the running balance: a replenishment
     * moves cash within the pool, and an issued kasbon already left the pool
     * when its advance was posted (Dr 1-1370 / Cr fund).
     */
    private function kasKecilExposure(): ?array
    {
        $funds = PettyCashFund::query()->where('is_active', true)->orderBy('code')->get();

        if ($funds->isEmpty()) {
            return null;
        }

        $rows = [];
        $dueTotal = 0.0;
        $kasbonTotal = 0.0;

        foreach ($funds as $fund) {
            $due = round(max(0.0, $this->pettyCashFunds->replenishmentDue($fund)), 2);
            $kasbon = $this->pettyCashFunds->outstandingKasbonTotal($fund);

            $dueTotal = round($dueTotal + $due, 2);
            $kasbonTotal = round($kasbonTotal + $kasbon, 2);

            $rows[] = [
                'code' => $fund->code,
                'name' => $fund->name,
                'replenishment_due' => $due,
                'outstanding_kasbon' => $kasbon,
            ];
        }

        return [
            'funds' => $rows,
            'replenishment_due_total' => $dueTotal,
            'outstanding_kasbon_total' => $kasbonTotal,
        ];
    }

    // -------------------------------------------------------------- helpers

    /**
     * Signed pool balances (debit − credit, cash is debit-normal) per
     * account: everything strictly BEFORE $before, or everything THROUGH
     * $through (inclusive).
     *
     * @param  array<int, int>  $pool
     * @return array<int, array{code: string, name: string, balance: float}> keyed by account id
     */
    private function poolBalances(array $pool, ?string $before = null, ?string $through = null): array
    {
        if ($pool === []) {
            return [];
        }

        $sums = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            ->when($before !== null, fn ($query) => $query->whereDate('fin_journals.journal_date', '<', $before))
            ->when($through !== null, fn ($query) => $query->whereDate('fin_journals.journal_date', '<=', $through))
            ->whereIn('fin_journal_lines.account_id', $pool)
            ->selectRaw('fin_journal_lines.account_id, COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->groupBy('fin_journal_lines.account_id')
            ->get()
            ->keyBy('account_id');

        $accounts = Account::query()->withTrashed()->whereIn('id', $pool)->get()->keyBy('id');
        $result = [];

        foreach ($pool as $accountId) {
            $account = $accounts->get($accountId);

            if ($account === null) {
                continue;
            }

            $sum = $sums->get($accountId);

            $result[$accountId] = [
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'balance' => $sum === null ? 0.0 : round((float) $sum->d - (float) $sum->c, 2),
            ];
        }

        return $result;
    }

    /** Posted (debit − credit) of one account id through a date. */
    private function accountBalance(int $accountId, string $through): float
    {
        $sums = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            ->whereDate('fin_journals.journal_date', '<=', $through)
            ->where('fin_journal_lines.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        return round((float) $sums->d - (float) $sums->c, 2);
    }

    /** "1–7 Agu" / "29 Agu–4 Sep" — the projection table's row label. */
    private function weekLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $fromMonth = $months[$from->month - 1];
        $toMonth = $months[$to->month - 1];

        return $from->month === $to->month
            ? "{$from->day}–{$to->day} {$fromMonth}"
            : "{$from->day} {$fromMonth}–{$to->day} {$toMonth}";
    }

    private function hasDeficitWarning(array $warnings): bool
    {
        foreach ($warnings as $warning) {
            if (str_contains($warning, 'NEGATIF mulai minggu')) {
                return true;
            }
        }

        return false;
    }

    /** Angka di kalimat asumsi/peringatan: "Rp 1.043.955.000". */
    private function rp(float $amount): string
    {
        $formatted = number_format(abs($amount), 2, ',', '.');
        // Whole rupiah reads as accountants write it; cents only when real.
        $formatted = str_ends_with($formatted, ',00') ? substr($formatted, 0, -3) : $formatted;

        return ($amount < 0 ? 'Rp -' : 'Rp ').$formatted;
    }
}
