<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Core\Support\Money;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Account;

/**
 * Ekualisasi pajak — the reconciliation working papers a pemeriksa pajak (or
 * an SP2DK letter) asks for: the books against the tax returns, one fiscal
 * year at a time, in the taste of FinanceFormService.
 *
 * THE HONESTY RULE IS THE PRODUCT HERE. Every figure below is read from the
 * database or is a computed difference between two figures that are; there is
 * no free-text row and no plug. The residual ("selisih belum terjelaskan") is
 * never forced to zero, never absorbed, never hidden when small — a zero
 * residual is printed as the tested fact it is, and a year with no data SAYS
 * SO rather than rendering a table of zeros a reader could mistake for
 * "nothing to reconcile". Each worksheet's decomposition deliberately stops
 * short of completeness-by-construction: the reconciling rows are derived from
 * SOURCE DOCUMENTS (invoices, run lines, payslips, bills) while the book side
 * is read from the POSTED LEDGER, so any drift between a document and the
 * journal it claims to have produced lands in the residual instead of being
 * defined away.
 *
 * THE FLAGSHIP DECISION — what "pendapatan menurut buku" means on the PPN
 * sheet. Two candidates existed: the GL balance of the 4-xxxx revenue accounts,
 * or the revenue engine's own cumulative rows. The GL is primary, for three
 * reasons. It is what the income statement the pemeriksa holds actually says;
 * it includes revenue the engine never touches (4-1300 maintenance is invoiced
 * directly on a billing basis BY DESIGN — RevenueRecognitionService skips that
 * scope — and a manual JV can credit revenue with no engine row at all); and
 * when the engine IS posted the two agree by construction, because the engine's
 * whole output is a journal on those same accounts. That agreement is not
 * assumed: the contract-balance movement row is derived from the engine's own
 * posted lines while the buku figure comes from the ledger, so an engine line
 * that disagrees with its own journal surfaces in the residual.
 *
 * WHY THE PPN DIVERGENCE IS THE POINT, NOT AN ERROR. For a PSAK 115
 * percentage-of-completion contractor, revenue follows progress while faktur
 * pajak follows termin billing — the two diverge BY DESIGN, and the year's
 * divergence is exactly the movement of the contract balance
 * (pendapatan kumulatif − tertagih kumulatif) over the posted runs. The sheet
 * derives that movement from fin_revenue_recognition_lines rather than
 * re-running any arithmetic of its own: one engine, one number, per the same
 * discipline that keeps FinanceFormService from re-deriving a bill's cost
 * category.
 */
class TaxEqualizationService
{
    /**
     * Bulan dalam bahasa Indonesia — the sixth copy in this codebase, for the
     * reason FinanceFormService::MONTHS documents: APP_LOCALE is 'en' with no
     * lang/ directory, and switching the app locale drags every validation
     * message with it.
     */
    private const MONTHS = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    /** The two accounts the POC engine adjusts; 4-1300 is billing-basis by design. */
    private const POC_REVENUE_CODES = ['4-1100', '4-1200'];

    private const SUBCON_EXPENSE_CODE = '5-1300';

    private const CONSTRUCTION_REVENUE_CODE = '4-1100';

    private const OFFICE_SALARY_CODE = '6-1100';

    private const PROJECT_WAGE_CODE = '5-1200';

    private const BPJS_EXPENSE_CODE = '6-1200';

    /**
     * The four worksheets of one fiscal year.
     *
     * @return array<string, mixed>
     */
    public function build(int $year): array
    {
        if ($year < 2000 || $year > 2100) {
            throw new LogicException('Tahun pajak di luar rentang yang wajar.');
        }

        return [
            'year' => $year,
            'worksheets' => [
                $this->ppnKeluaran($year),
                $this->ppnMasukan($year),
                $this->pph21($year),
                $this->pphWithholding($year),
            ],
        ];
    }

    // --------------------------------------------- worksheet 1: PPN keluaran

    /**
     * Pendapatan menurut buku vs DPP faktur pajak keluaran.
     *
     * The decomposition of buku − SPT, every part derived:
     *
     *   + pendapatan diakui belum ditagih     movement of positive contract
     *   − penagihan mendahului pendapatan     balances / negative ones, from
     *                                         the year's POSTED run lines
     *   + invoice dibatalkan lintas tahun     credit still in this year's
     *                                         books, reversal landed later
     *   − pembalikan atas invoice tahun lalu  the mirror case
     *   + pendapatan di luar penagihan        4-xxxx moved by anything that is
     *                                         not an invoice, a cancellation
     *                                         or the POC engine (JV manual)
     *
     * A same-year cancellation contributes zero to every row on purpose: its
     * credit and its reversal both sit in buku and its DPP is out of the SPT
     * side, so there is nothing to reconcile — only the spent faktur serial to
     * warn about.
     *
     * @return array<string, mixed>
     */
    public function ppnKeluaran(int $year): array
    {
        $accounts = $this->revenueAccounts();
        $accountIds = array_keys($accounts);

        [$bookNet, $bookLineCount] = $this->ledgerNet($accountIds, $year, 'credit');

        $approved = DB::table('fin_ar_invoices')
            ->whereNull('deleted_at')
            ->where('status', DocumentStatus::Approved->value)
            ->whereYear('invoice_date', $year)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(dpp), 0) as dpp')
            ->first();

        $cancelled = DB::table('fin_ar_invoices')
            ->whereNull('deleted_at')
            ->where('status', DocumentStatus::Cancelled->value)
            ->whereYear('invoice_date', $year)
            ->get(['id', 'dpp', 'faktur_pajak_no']);

        $movements = $this->contractBalanceMovements($year);
        $postedRunMonths = $this->postedRunMonths($year);

        if ($bookLineCount === 0 && (int) $approved->n === 0 && $cancelled->isEmpty() && $postedRunMonths === []) {
            return $this->emptyWorksheet(
                'ppn_keluaran',
                'Ekualisasi PPN Keluaran',
                "Tidak ada data pendapatan maupun faktur pajak keluaran untuk tahun {$year}."
            );
        }

        $dppApproved = round((float) $approved->dpp, 2);

        $rows = [
            $this->row('Pendapatan menurut buku (akun pendapatan 4-xxxx)', $bookNet, null, null, 'buku'),
            $this->row('DPP faktur pajak keluaran (invoice disetujui)', null, $dppApproved, null, 'spt'),
        ];

        $pocUp = round(array_sum(array_filter($movements, fn (float $delta): bool => $delta > 0)), 2);
        $pocDown = round(array_sum(array_filter($movements, fn (float $delta): bool => $delta < 0)), 2);

        if (abs($pocUp) >= 0.005) {
            $rows[] = $this->row('Pendapatan diakui belum ditagih (kenaikan aset kontrak)', null, null, $pocUp, 'derived');
        }

        if (abs($pocDown) >= 0.005) {
            $rows[] = $this->row('Penagihan mendahului pendapatan (kenaikan liabilitas kontrak)', null, null, $pocDown, 'derived');
        }

        [$forward, $backward] = $this->crossYearCancellations($year, $accountIds, $cancelled);

        if (abs($forward) >= 0.005) {
            $rows[] = $this->row(
                'Invoice dibatalkan setelah tahun berjalan (pendapatan masih di buku tahun ini)',
                null, null, $forward, 'derived'
            );
        }

        if (abs($backward) >= 0.005) {
            $rows[] = $this->row(
                'Pembalikan tahun ini atas invoice tahun sebelumnya',
                null, null, $backward, 'derived'
            );
        }

        [$otherNet, $otherCount] = $this->ledgerNet(
            $accountIds,
            $year,
            'credit',
            excludeReferenceTypes: ['ar_invoice', 'ar_invoice_cancellation', 'revenue_recognition'],
        );

        if ($otherCount > 0 && abs($otherNet) >= 0.005) {
            $rows[] = $this->row(
                'Pendapatan dibukukan di luar penagihan & pengakuan (jurnal manual dll.)',
                null, null, $otherNet, 'derived'
            );
        }

        $warnings = [];

        // Revenue in a POC month no posted run has measured is a statement the
        // books cannot defend yet — said as a row with its own figure, never
        // silence. 4-1300 stays out: maintenance is billing-basis by design
        // and would cry wolf every month.
        $pocAccountIds = array_keys(array_filter($accounts, fn (string $code): bool => in_array($code, self::POC_REVENUE_CODES, true)));
        $unrecognisedMonths = $this->monthsWithMovementWithoutRun($pocAccountIds, $year, $postedRunMonths);

        if ($unrecognisedMonths !== []) {
            $labels = implode(', ', array_map(fn (int $month): string => self::MONTHS[$month], array_keys($unrecognisedMonths)));
            $rows[] = $this->row(
                "Pendapatan konstruksi/integrasi pada bulan tanpa run pengakuan terposting ({$labels})",
                round(array_sum($unrecognisedMonths), 2), null, null, 'warning'
            );
        }

        $spentFakturs = $cancelled->filter(fn (object $invoice): bool => trim((string) $invoice->faktur_pajak_no) !== '');

        if ($spentFakturs->isNotEmpty()) {
            $warnings[] = sprintf(
                '%d invoice dibatalkan masih memegang nomor faktur pajak (DPP %s) — pastikan nota pembatalan dilaporkan ke DJP.',
                $spentFakturs->count(),
                Money::format(round((float) $spentFakturs->sum('dpp'), 2)),
            );
        }

        // buku − SPT − every derived amount, INCLUDING the ones too small to
        // print: an omitted half-cent must not resurface as residual.
        $residual = round($bookNet - $dppApproved - $pocUp - $pocDown - $forward - $backward - ($otherCount > 0 ? $otherNet : 0.0), 2);

        return [
            'key' => 'ppn_keluaran',
            'title' => 'Ekualisasi PPN Keluaran',
            'rows' => $rows,
            'residual' => $this->residual($residual),
            'warnings' => $warnings,
        ];
    }

    // ---------------------------------------------- worksheet 2: PPN masukan

    /**
     * DPP tagihan berfaktur vs total DPP tagihan vendor.
     *
     * The three figures partition one population — every approved bill either
     * holds a faktur or does not — so a clean year closes to zero and a dirty
     * one shows exactly where. Uang muka needs NO netting row: ApBillService
     * prices the final bill's stored DPP net of the approved advance it
     * consumes, so summing stored DPP counts each rupiah once. The advance's
     * share is still disclosed, because a reader comparing against the PO
     * total would otherwise suspect a double count that is not there.
     *
     * @return array<string, mixed>
     */
    public function ppnMasukan(int $year): array
    {
        $bills = DB::table('fin_ap_bills')
            ->whereNull('deleted_at')
            ->where('status', DocumentStatus::Approved->value)
            ->whereYear('bill_date', $year)
            ->get(['id', 'dpp', 'ppn_amount', 'faktur_pajak_no', 'is_advance']);

        $cancelled = DB::table('fin_ap_bills')
            ->whereNull('deleted_at')
            ->where('status', DocumentStatus::Cancelled->value)
            ->whereYear('bill_date', $year)
            ->get(['id', 'dpp', 'faktur_pajak_no']);

        if ($bills->isEmpty() && $cancelled->isEmpty()) {
            return $this->emptyWorksheet(
                'ppn_masukan',
                'Ekualisasi PPN Masukan',
                "Tidak ada tagihan vendor untuk tahun {$year}."
            );
        }

        $withFaktur = $bills->filter(fn (object $bill): bool => trim((string) $bill->faktur_pajak_no) !== '');
        $withoutFaktur = $bills->filter(fn (object $bill): bool => trim((string) $bill->faktur_pajak_no) === '');

        $total = round((float) $bills->sum('dpp'), 2);
        $creditable = round((float) $withFaktur->sum('dpp'), 2);
        $nonCreditable = round((float) $withoutFaktur->sum('dpp'), 2);

        $rows = [
            $this->row('Total DPP tagihan vendor disetujui', $total, null, null, 'buku'),
            $this->row('DPP tagihan berfaktur pajak (dasar kredit PPN masukan)', null, $creditable, null, 'spt'),
        ];

        if ($withoutFaktur->isNotEmpty()) {
            $rows[] = $this->row(
                sprintf('Tagihan tanpa faktur pajak — PPN tidak dapat dikreditkan (%d tagihan)', $withoutFaktur->count()),
                null, null, $nonCreditable, 'derived'
            );
        }

        if ($withFaktur->isNotEmpty()) {
            $rows[] = $this->row(
                'PPN masukan atas tagihan berfaktur',
                null, round((float) $withFaktur->sum('ppn_amount'), 2), null, 'info'
            );
        }

        $advances = $bills->filter(fn (object $bill): bool => (bool) $bill->is_advance);

        if ($advances->isNotEmpty()) {
            $rows[] = $this->row(
                sprintf('Uang muka vendor di dalam total — tagihan final sudah neto uang muka (%d tagihan)', $advances->count()),
                round((float) $advances->sum('dpp'), 2), null, null, 'info'
            );
        }

        $warnings = [];
        $cancelledWithFaktur = $cancelled->filter(fn (object $bill): bool => trim((string) $bill->faktur_pajak_no) !== '');

        if ($cancelledWithFaktur->isNotEmpty()) {
            $warnings[] = sprintf(
                '%d tagihan dibatalkan masih mencantumkan nomor faktur pajak (DPP %s) — PPN masukannya tidak boleh dikreditkan.',
                $cancelledWithFaktur->count(),
                Money::format(round((float) $cancelledWithFaktur->sum('dpp'), 2)),
            );
        }

        return [
            'key' => 'ppn_masukan',
            'title' => 'Ekualisasi PPN Masukan',
            'rows' => $rows,
            'residual' => $this->residual(round($total - $creditable - $nonCreditable, 2)),
            'warnings' => $warnings,
        ];
    }

    // -------------------------------------------------- worksheet 3: PPh 21

    /**
     * Beban gaji/upah menurut buku vs bruto SPT Masa PPh 21.
     *
     * The book side mirrors what PayrollPostingService actually posts: office
     * gross debits 6-1100 and PROJECT gross debits 5-1200 — a sheet reading
     * 6-1100 alone loses every site worker's wages. 6-1200 (employer BPJS) is
     * printed as information and kept OUT of the arithmetic: PayrollService
     * computes the PPh 21 base on the cash gross only (its own documented
     * simplification — the JKK/JKM/Kesehatan company shares that a strict
     * fiscal reading would add are not in gross_income), so none of 6-1200 is
     * in bruto SPT and pretending part of it is would manufacture a difference
     * the SPT cannot show. THR is INSIDE gross_income (a THR payslip's
     * gross_income IS its thr_amount), verified by the suite — disclosed as
     * part of the bruto, never added on top.
     *
     * @return array<string, mixed>
     */
    public function pph21(int $year): array
    {
        $wageAccounts = $this->accountIdsByCode([self::OFFICE_SALARY_CODE, self::PROJECT_WAGE_CODE, self::BPJS_EXPENSE_CODE]);

        $officeIds = $this->idsFor($wageAccounts, [self::OFFICE_SALARY_CODE]);
        $projectIds = $this->idsFor($wageAccounts, [self::PROJECT_WAGE_CODE]);
        $bpjsIds = $this->idsFor($wageAccounts, [self::BPJS_EXPENSE_CODE]);

        [$office, $officeCount] = $this->ledgerNet($officeIds, $year, 'debit');
        [$project, $projectCount] = $this->ledgerNet($projectIds, $year, 'debit');
        [$bpjs, $bpjsCount] = $this->ledgerNet($bpjsIds, $year, 'debit');

        $runsExist = DB::table('hr_payroll_runs')
            ->whereNull('deleted_at')
            ->where('period_year', $year)
            ->exists();

        if ($officeCount === 0 && $projectCount === 0 && $bpjsCount === 0 && ! $runsExist) {
            return $this->emptyWorksheet(
                'pph21',
                'Ekualisasi PPh 21',
                "Tidak ada data payroll maupun beban gaji untuk tahun {$year}."
            );
        }

        $slips = DB::table('hr_payslips')
            ->join('hr_payroll_runs', 'hr_payroll_runs.id', '=', 'hr_payslips.payroll_run_id')
            ->whereNull('hr_payroll_runs.deleted_at')
            ->where('hr_payroll_runs.period_year', $year)
            ->whereIn('hr_payroll_runs.status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->selectRaw('COALESCE(SUM(hr_payslips.gross_income), 0) as gross, COALESCE(SUM(hr_payslips.thr_amount), 0) as thr')
            ->first();

        $gross = round((float) $slips->gross, 2);
        $thr = round((float) $slips->thr, 2);

        // Whatever moved the wage accounts that was NOT a payroll journal —
        // the honorarium JV, the petty-cash daily wage — is derived straight
        // off the ledger's own reference types, sign and all. Wages paid past
        // payroll are exactly what a pemeriksa hunts on this sheet.
        [$nonPayroll, $nonPayrollCount] = $this->ledgerNet(
            array_merge($officeIds, $projectIds),
            $year,
            'debit',
            excludeReferenceTypes: ['payroll_run'],
        );

        $rows = [
            $this->row('Beban gaji & tunjangan menurut buku (6-1100)', $office, null, null, 'buku'),
            $this->row('Beban upah proyek menurut buku (5-1200)', $project, null, null, 'buku'),
            $this->row('Bruto SPT Masa PPh 21 (slip gaji run disetujui)', null, $gross, null, 'spt'),
        ];

        if ($nonPayrollCount > 0 && abs($nonPayroll) >= 0.005) {
            $rows[] = $this->row(
                'Beban gaji/upah dibukukan di luar modul payroll (JV manual, kas kecil)',
                null, null, $nonPayroll, 'derived'
            );
        }

        if (abs($thr) >= 0.005) {
            $rows[] = $this->row('THR di dalam bruto SPT (bagian dari bruto, bukan tambahan)', null, $thr, null, 'info');
        }

        if ($bpjsCount > 0) {
            $rows[] = $this->row(
                'Iuran BPJS perusahaan (6-1200) — di luar basis bruto PPh 21 aplikasi',
                $bpjs, null, null, 'info'
            );
        }

        $pending = DB::table('hr_payroll_runs')
            ->whereNull('deleted_at')
            ->where('period_year', $year)
            ->whereIn('status', [DocumentStatus::Draft->value, DocumentStatus::Submitted->value])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total_gross), 0) as gross')
            ->first();

        if ((int) $pending->n > 0) {
            $rows[] = $this->row(
                sprintf('Run payroll belum disetujui — belum di buku maupun SPT (%d run)', (int) $pending->n),
                null, round((float) $pending->gross, 2), null, 'warning'
            );
        }

        $warnings = [
            'Basis PPh 21 aplikasi adalah bruto kas (gaji + tunjangan + lembur + THR); '
                .'iuran JKK/JKM/Kesehatan perusahaan tidak ditambahkan ke bruto — simplifikasi '
                .'PayrollService yang terdokumentasi, sehingga 6-1200 seluruhnya di luar ekualisasi ini.',
        ];

        /*
         * A run that is approved but has NO journal (the pre-PayrollPostingService
         * demo runs are exactly this) leaves bruto in the SPT side and nothing
         * in the books. That difference is REAL — the books understate wages —
         * so it deliberately STAYS IN THE RESIDUAL, loud, and the warning only
         * names the cause. A derived row here would print "all reconciled"
         * over books that are genuinely missing their wage expense.
         */
        $unjournalled = DB::table('hr_payroll_runs')
            ->whereNull('deleted_at')
            ->where('period_year', $year)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('fin_journals')
                    ->where('fin_journals.reference_type', 'payroll_run')
                    ->whereColumn('fin_journals.reference_id', 'hr_payroll_runs.id')
                    ->where('fin_journals.status', PostingStatus::Posted->value)
                    ->whereNull('fin_journals.deleted_at');
            })
            ->get(['code', 'total_gross']);

        if ($unjournalled->isNotEmpty()) {
            $warnings[] = sprintf(
                'Run payroll disetujui tanpa jurnal di buku besar: %s (bruto %s) — beban gajinya tidak ada '
                .'di buku, dan selisih residu di bawah memuat persis kekurangan itu.',
                $unjournalled->pluck('code')->implode(', '),
                Money::format(round((float) $unjournalled->sum('total_gross'), 2)),
            );
        }

        $residual = round($office + $project - $gross - ($nonPayrollCount > 0 ? $nonPayroll : 0.0), 2);

        return [
            'key' => 'pph21',
            'title' => 'Ekualisasi PPh 21',
            'rows' => $rows,
            'residual' => $this->residual($residual),
            'warnings' => $warnings,
        ];
    }

    // -------------------------------------------- worksheet 4: PPh dipotong

    /**
     * Two panels on one sheet, each row stamped with its panel.
     *
     * PANEL A — dipotong perusahaan. The e-Bupot base per bill is
     * pph_amount ÷ its tax row's rate, which recovers the FULL opname gross:
     * ClaimService computes PPh final on the whole period DPP while the bill's
     * stored DPP is net of the uang muka recovered, and the bill's cost leg
     * debits that same gross to 5-1300. So base and book meet on the same
     * number for every properly withheld opname, and what remains are the
     * derived rows: subcon cost that withheld nothing, and 5-1300 moved by
     * anything that is not a vendor bill. The residual keeps the per-bill
     * rounding of the rate reconstruction and any bill whose journal disagrees
     * with its own columns — deliberately NOT folded into a derived row,
     * because a decomposition that is complete by construction proves nothing.
     *
     * PANEL B — dipotong pelanggan atas kita. A SOFT comparison, said plainly:
     * the customer withholds PPh final on RECEIPT (PP 9/2022) while the books
     * earn by progress, so the two bases are printed side by side — the base
     * the certificates actually cover versus 2,65% of the construction revenue
     * — and their difference is labelled timing, never pushed into a residual.
     * PPh 23 bases are printed as information without a book comparison: jasa
     * costs spread over many expense accounts, and pretending one account
     * covers them would be a forged comparison.
     *
     * @return array<string, mixed>
     */
    public function pphWithholding(int $year): array
    {
        $warnings = [];

        // ---------------------------------------- panel A: withheld BY us

        $subconIds = array_keys($this->accountIdsByCode([self::SUBCON_EXPENSE_CODE]));

        [$subconExpense, $subconLineCount] = $this->ledgerNet($subconIds, $year, 'debit');

        $withholdingBills = DB::table('fin_ap_bills')
            ->leftJoin('fin_taxes', 'fin_taxes.id', '=', 'fin_ap_bills.pph_tax_id')
            ->whereNull('fin_ap_bills.deleted_at')
            ->where('fin_ap_bills.status', DocumentStatus::Approved->value)
            ->whereYear('fin_ap_bills.bill_date', $year)
            ->where('fin_ap_bills.pph_amount', '>', 0)
            ->get([
                'fin_ap_bills.id', 'fin_ap_bills.code', 'fin_ap_bills.pph_amount',
                'fin_taxes.code as tax_code', 'fin_taxes.rate as tax_rate',
            ]);

        $unbilled = $this->approvedUnbilledClaims($year);

        $panelAEmpty = $subconLineCount === 0 && $withholdingBills->isEmpty() && (int) $unbilled->n === 0;

        $rows = [];
        $residual = null;

        if ($panelAEmpty) {
            $warnings[] = "Tidak ada pemotongan PPh vendor maupun beban subkontraktor untuk tahun {$year}.";
        } else {
            $isFinal = fn (object $bill): bool => str_starts_with((string) $bill->tax_code, 'PPH4A2');
            $finalBills = $withholdingBills->filter($isFinal);
            $otherBills = $withholdingBills->reject($isFinal);

            $basisFinal = $this->reconstructedBases($finalBills, $warnings);
            $basis23 = $this->reconstructedBases($otherBills, $warnings);

            $rows[] = $this->row('PPh dipotong perusahaan atas vendor (e-Bupot)', null, null, null, 'section', 'dipotong_perusahaan');
            $rows[] = $this->row('Beban subkontraktor menurut buku (5-1300)', $subconExpense, null, null, 'buku', 'dipotong_perusahaan');
            $rows[] = $this->row('Basis pemotongan PPh final konstruksi (tagihan disetujui)', null, $basisFinal, null, 'spt', 'dipotong_perusahaan');
            $rows[] = $this->row(
                'PPh final konstruksi dipotong perusahaan',
                null, round((float) $finalBills->sum('pph_amount'), 2), null, 'info', 'dipotong_perusahaan'
            );

            // 5-1300 split BY THE LEDGER'S OWN REFERENCES, never by
            // re-deriving a bill's cost category — FinanceFormService's rule:
            // the journal that carries the cost is the truth about it.
            $finalBillIds = $finalBills->pluck('id')->map(fn ($id): int => (int) $id)->all();
            [$unwithheld, $unwithheldCount] = $this->subconExpenseOutsideBills($subconIds, $year, $finalBillIds);

            if ($unwithheldCount > 0 && abs($unwithheld) >= 0.005) {
                $rows[] = $this->row(
                    'Beban subkon tanpa pemotongan PPh final',
                    null, null, $unwithheld, 'derived', 'dipotong_perusahaan'
                );
            }

            [$nonBill, $nonBillCount] = $this->ledgerNet($subconIds, $year, 'debit', excludeReferenceTypes: ['ap_bill']);

            if ($nonBillCount > 0 && abs($nonBill) >= 0.005) {
                $rows[] = $this->row(
                    'Beban subkontraktor dari sumber selain tagihan vendor (JV, pembatalan)',
                    null, null, $nonBill, 'derived', 'dipotong_perusahaan'
                );
            }

            if ($otherBills->isNotEmpty()) {
                $rows[] = $this->row(
                    'Basis pemotongan PPh 23 jasa (beban tersebar di banyak akun — tidak dibandingkan)',
                    null, $basis23, null, 'info', 'dipotong_perusahaan'
                );
                $rows[] = $this->row(
                    'PPh 23 dipotong perusahaan',
                    null, round((float) $otherBills->sum('pph_amount'), 2), null, 'info', 'dipotong_perusahaan'
                );
            }

            if ((int) $unbilled->n > 0) {
                $rows[] = $this->row(
                    sprintf('Opname subkon disetujui belum ditagihkan — PPh final belum terutang (%d opname)', (int) $unbilled->n),
                    round((float) $unbilled->billable, 2), null, null, 'warning', 'dipotong_perusahaan'
                );
            }

            $residual = round(
                $subconExpense - $basisFinal
                    - ($unwithheldCount > 0 ? $unwithheld : 0.0)
                    - ($nonBillCount > 0 ? $nonBill : 0.0),
                2,
            );
        }

        // ------------------------------------ panel B: withheld FROM us

        $withheld = DB::table('fin_payment_withholdings')
            ->join('fin_payments', 'fin_payments.id', '=', 'fin_payment_withholdings.payment_id')
            ->whereNull('fin_payments.deleted_at')
            ->where('fin_payments.status', 'posted')
            ->whereYear('fin_payments.payment_date', $year)
            ->where('fin_payment_withholdings.type', 'pph_final')
            ->get(['fin_payment_withholdings.amount', 'fin_payment_withholdings.certificate_no']);

        $constructionIds = array_keys($this->accountIdsByCode([self::CONSTRUCTION_REVENUE_CODE]));
        [$constructionRevenue, $revenueLineCount] = $this->ledgerNet($constructionIds, $year, 'credit');

        if ($withheld->isEmpty() && $revenueLineCount === 0) {
            $warnings[] = "Tidak ada bukti potong pelanggan maupun pendapatan konstruksi untuk tahun {$year}.";
        } else {
            $rate = Erp::float('tax.pph_final_construction.pelaksanaan_bersertifikat', 2.65);
            $amount = round((float) $withheld->sum('amount'), 2);
            $certificated = $withheld->filter(fn (object $row): bool => trim((string) $row->certificate_no) !== '')->count();

            $rows[] = $this->row('PPh final konstruksi dipotong pelanggan atas kita (PP 9/2022)', null, null, null, 'section', 'dipotong_pelanggan');
            $rows[] = $this->row(
                sprintf('PPh final dipotong pelanggan — bukti potong atas penerimaan terposting (%d dari %d bernomor)', $certificated, $withheld->count()),
                null, $amount, null, 'info', 'dipotong_pelanggan'
            );

            if ($rate > 0) {
                $rows[] = $this->row(
                    sprintf('Basis penerimaan yang dicakup bukti potong (PPh ÷ %s%%)', $this->rateLabel($rate)),
                    null, round($amount * 100 / $rate, 2), null, 'info', 'dipotong_pelanggan'
                );
            }

            $rows[] = $this->row('Pendapatan jasa konstruksi menurut buku (4-1100)', $constructionRevenue, null, null, 'info', 'dipotong_pelanggan');

            if ($rate > 0) {
                $expected = round($constructionRevenue * $rate / 100, 2);
                $rows[] = $this->row(
                    sprintf('PPh final seharusnya bila seluruh pendapatan dipotong (%s%% × pendapatan)', $this->rateLabel($rate)),
                    $expected, null, null, 'info', 'dipotong_pelanggan'
                );
                $rows[] = $this->row(
                    'Selisih PPh final dipotong vs seharusnya',
                    null, null, round($amount - $expected, 2), 'info', 'dipotong_pelanggan'
                );
            }

            $warnings[] = 'Perbandingan PPh final pelanggan bersifat indikatif: pelanggan memotong saat '
                .'PEMBAYARAN diterima, sedangkan buku mengakui pendapatan menurut kemajuan — perbedaan waktu '
                .'antara keduanya wajar dan kedua basisnya dicetak agar dapat ditelusuri.';
            $warnings[] = sprintf(
                'Tarif pembanding %s%% adalah PPh final pelaksana konstruksi bersertifikat (PP 9/2022) dari '
                .'konfigurasi; kontrak dengan klasifikasi lain menggeser angka "seharusnya", bukan angka bukti potong.',
                $this->rateLabel(Erp::float('tax.pph_final_construction.pelaksanaan_bersertifikat', 2.65)),
            );

            if ($certificated < $withheld->count()) {
                $warnings[] = sprintf(
                    '%d bukti potong belum mencantumkan nomor — tanpa nomor, kredit PPh final tidak dapat dibuktikan.',
                    $withheld->count() - $certificated,
                );
            }
        }

        if ($rows === []) {
            return [
                'key' => 'pph_dipotong',
                'title' => 'Ekualisasi PPh Dipotong',
                'rows' => [],
                'residual' => null,
                'warnings' => $warnings,
            ];
        }

        return [
            'key' => 'pph_dipotong',
            'title' => 'Ekualisasi PPh Dipotong',
            'rows' => $rows,
            'residual' => $residual === null
                ? null
                : $this->residual($residual, 'Selisih belum terjelaskan (PPh dipotong perusahaan)'),
            'warnings' => $warnings,
        ];
    }

    // ------------------------------------------------------------- internals

    /**
     * Year movement of every contract balance, derived from the engine's own
     * POSTED lines — one arithmetic, the engine's. Per contract the deltas
     * chain (each run measures against the last posted one, and posting order
     * is enforced), so the year's revenue_adjustment sum IS balance(end) −
     * balance(start) even for the first-ever run's cumulative catch-up.
     *
     * @return array<int, float> contract_id => movement
     */
    private function contractBalanceMovements(int $year): array
    {
        $rows = DB::table('fin_revenue_recognition_lines')
            ->join('fin_revenue_recognition_runs', 'fin_revenue_recognition_runs.id', '=', 'fin_revenue_recognition_lines.run_id')
            ->where('fin_revenue_recognition_runs.status', PostingStatus::Posted->value)
            ->where('fin_revenue_recognition_runs.period_year', $year)
            ->groupBy('fin_revenue_recognition_lines.contract_id')
            ->selectRaw('fin_revenue_recognition_lines.contract_id, COALESCE(SUM(fin_revenue_recognition_lines.revenue_adjustment), 0) as movement')
            ->get();

        $movements = [];

        foreach ($rows as $row) {
            $movements[(int) $row->contract_id] = round((float) $row->movement, 2);
        }

        return $movements;
    }

    /** @return array<int, int> months (1-12) that have a posted run */
    private function postedRunMonths(int $year): array
    {
        return DB::table('fin_revenue_recognition_runs')
            ->where('status', PostingStatus::Posted->value)
            ->where('period_year', $year)
            ->pluck('period_month')
            ->map(fn ($month): int => (int) $month)
            ->all();
    }

    /**
     * The two cross-year cancellation rows.
     *
     * Forward: an invoice of THIS year whose reversal landed (or will land)
     * outside it — its credit still stands in this year's books while its DPP
     * has left the SPT side. Backward: this year's reversal of an EARLIER
     * year's invoice — a debit with no invoice beside it. A same-year
     * cancellation belongs to neither: credit and reversal cancel inside buku.
     *
     * @param  Collection<int, object>  $cancelledThisYear
     * @return array{0: float, 1: float}
     */
    private function crossYearCancellations(int $year, array $revenueAccountIds, $cancelledThisYear): array
    {
        $reversalYears = [];

        if ($cancelledThisYear->isNotEmpty()) {
            $reversals = DB::table('fin_journals')
                ->where('reference_type', 'ar_invoice_cancellation')
                ->whereIn('reference_id', $cancelledThisYear->pluck('id')->all())
                ->where('status', PostingStatus::Posted->value)
                ->whereNull('deleted_at')
                ->groupBy('reference_id')
                ->selectRaw('reference_id, MIN(journal_date) as reversed_on')
                ->get();

            foreach ($reversals as $reversal) {
                $reversalYears[(int) $reversal->reference_id] = (int) substr((string) $reversal->reversed_on, 0, 4);
            }
        }

        $forward = 0.0;

        foreach ($cancelledThisYear as $invoice) {
            // No reversal journal at all degrades to "still in the books",
            // which is what the ledger side genuinely says.
            if (($reversalYears[(int) $invoice->id] ?? null) !== $year) {
                $forward += (float) $invoice->dpp;
            }
        }

        // This year's reversal debits, per invoice, kept only when the invoice
        // itself is dated in an earlier year.
        $reversalNets = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->whereIn('fin_journal_lines.account_id', $revenueAccountIds)
            ->where('fin_journals.reference_type', 'ar_invoice_cancellation')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            ->whereDate('fin_journals.journal_date', '>=', sprintf('%04d-01-01', $year))
            ->whereDate('fin_journals.journal_date', '<=', sprintf('%04d-12-31', $year))
            ->groupBy('fin_journals.reference_id')
            ->selectRaw('fin_journals.reference_id, COALESCE(SUM(fin_journal_lines.debit - fin_journal_lines.credit), 0) as net')
            ->get();

        $backward = 0.0;

        if ($reversalNets->isNotEmpty()) {
            $invoiceYears = DB::table('fin_ar_invoices')
                ->whereIn('id', $reversalNets->pluck('reference_id')->all())
                ->pluck('invoice_date', 'id');

            foreach ($reversalNets as $reversal) {
                $invoiceYear = (int) substr((string) ($invoiceYears[(int) $reversal->reference_id] ?? ''), 0, 4);

                if ($invoiceYear !== 0 && $invoiceYear < $year) {
                    // The reversal REDUCES buku, so its contribution to
                    // buku − SPT carries a minus sign.
                    $backward -= (float) $reversal->net;
                }
            }
        }

        return [round($forward, 2), round($backward, 2)];
    }

    /**
     * @param  array<int, int>  $accountIds
     * @return array<int, float> month => net movement, only months without a posted run
     */
    private function monthsWithMovementWithoutRun(array $accountIds, int $year, array $postedRunMonths): array
    {
        $lines = $this->linesQuery($accountIds, $year)
            ->get(['fin_journals.journal_date', 'fin_journal_lines.debit', 'fin_journal_lines.credit']);

        $perMonth = [];

        foreach ($lines as $line) {
            $month = (int) substr((string) $line->journal_date, 5, 2);
            $perMonth[$month] = round(($perMonth[$month] ?? 0.0) + (float) $line->credit - (float) $line->debit, 2);
        }

        ksort($perMonth);

        return array_filter(
            $perMonth,
            fn (float $net, int $month): bool => abs($net) >= 0.005 && ! in_array($month, $postedRunMonths, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Bases reconstructed from pph_amount ÷ rate, one bill at a time. A bill
     * whose tax row is missing or carries a zero rate cannot state its base —
     * reported as a warning naming the bill, never guessed.
     *
     * @param  Collection<int, object>  $bills
     * @param  array<int, string>  $warnings
     */
    private function reconstructedBases($bills, array &$warnings): float
    {
        $basis = 0.0;

        foreach ($bills as $bill) {
            $rate = (float) ($bill->tax_rate ?? 0);

            if ($rate <= 0) {
                $warnings[] = sprintf(
                    'Tagihan %s memotong PPh %s namun tarif pajaknya tidak dapat dibaca — basisnya tidak ikut dihitung.',
                    (string) $bill->code,
                    Money::format(round((float) $bill->pph_amount, 2)),
                );

                continue;
            }

            $basis += round((float) $bill->pph_amount * 100 / $rate, 2);
        }

        return round($basis, 2);
    }

    /**
     * 5-1300 movement carried by ap_bill journals whose bill did NOT withhold
     * PPh final — read off the ledger's references, so a manual bill the
     * operator classified as subcon shows up exactly as the ledger carries it.
     *
     * @param  array<int, int>  $subconAccountIds
     * @param  array<int, int>  $finalBillIds
     * @return array{0: float, 1: int}
     */
    private function subconExpenseOutsideBills(array $subconAccountIds, int $year, array $finalBillIds): array
    {
        $nets = $this->linesQuery($subconAccountIds, $year)
            ->where('fin_journals.reference_type', 'ap_bill')
            ->groupBy('fin_journals.reference_id')
            ->selectRaw('fin_journals.reference_id, COALESCE(SUM(fin_journal_lines.debit - fin_journal_lines.credit), 0) as net, COUNT(*) as n')
            ->get();

        $total = 0.0;
        $count = 0;

        foreach ($nets as $net) {
            if (in_array((int) $net->reference_id, $finalBillIds, true)) {
                continue;
            }

            $total += (float) $net->net;
            $count += (int) $net->n;
        }

        return [round($total, 2), $count];
    }

    /**
     * Approved opnames no live bill has picked up: cost and withholding both
     * still to come. DP claims stay out (they never carry PPh — AdvanceService
     * mints them with pph_amount 0 and pays through its own path).
     */
    private function approvedUnbilledClaims(int $year): object
    {
        return DB::table('scm_progress_claims')
            ->whereNull('deleted_at')
            ->where('status', DocumentStatus::Approved->value)
            ->where('is_advance', false)
            ->whereYear('period_end', $year)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('fin_ap_bills')
                    ->whereColumn('fin_ap_bills.subcontract_claim_id', 'scm_progress_claims.id')
                    ->whereNull('fin_ap_bills.deleted_at')
                    ->whereNot('fin_ap_bills.status', DocumentStatus::Cancelled->value);
            })
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(gross_amount - advance_recovery_amount), 0) as billable')
            ->first();
    }

    // ------------------------------------------------------- ledger reading

    /**
     * Every postable revenue account, deleted ones included: an archived
     * account still answers for the year its lines are in.
     *
     * @return array<int, string> id => code
     */
    private function revenueAccounts(): array
    {
        return Account::query()
            ->withTrashed()
            ->where('account_type', 'revenue')
            ->where('is_postable', true)
            ->pluck('code', 'id')
            ->all();
    }

    /** @return array<int, string> id => code */
    private function accountIdsByCode(array $codes): array
    {
        return Account::query()
            ->withTrashed()
            ->whereIn('code', $codes)
            ->pluck('code', 'id')
            ->all();
    }

    /** @return array<int, int> */
    private function idsFor(array $accounts, array $codes): array
    {
        return array_keys(array_filter($accounts, fn (string $code): bool => in_array($code, $codes, true)));
    }

    /**
     * Net ledger movement plus the LINE COUNT behind it, so "zero because
     * nothing happened" and "zero because it nets out" stay distinguishable —
     * that distinction is what the empty-year rule rides on.
     *
     * Predicates mirror ReportService::sumsPerAccount line for line (posted
     * only, deleted_at null, inclusive whereDate bounds): a worksheet that
     * disagreed with the trial balance would impeach both.
     *
     * @param  array<int, int>  $accountIds
     * @param  array<int, string>|null  $excludeReferenceTypes  journals whose
     *                                                          reference_type is in this list (NULL never matches an exclusion
     *                                                          list, so manual JVs stay counted) are left out
     * @return array{0: float, 1: int}
     */
    private function ledgerNet(array $accountIds, int $year, string $positiveSide, ?array $excludeReferenceTypes = null): array
    {
        if ($accountIds === []) {
            return [0.0, 0];
        }

        $query = $this->linesQuery($accountIds, $year);

        if ($excludeReferenceTypes !== null) {
            $query->where(function ($sub) use ($excludeReferenceTypes): void {
                $sub->whereNotIn('fin_journals.reference_type', $excludeReferenceTypes)
                    ->orWhereNull('fin_journals.reference_type');
            });
        }

        $row = $query
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit), 0) as debit, COALESCE(SUM(fin_journal_lines.credit), 0) as credit, COUNT(*) as n')
            ->first();

        $net = $positiveSide === 'credit'
            ? (float) $row->credit - (float) $row->debit
            : (float) $row->debit - (float) $row->credit;

        return [round($net, 2), (int) $row->n];
    }

    /** @param array<int, int> $accountIds */
    private function linesQuery(array $accountIds, int $year)
    {
        return DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->whereIn('fin_journal_lines.account_id', $accountIds)
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            ->whereDate('fin_journals.journal_date', '>=', sprintf('%04d-01-01', $year))
            ->whereDate('fin_journals.journal_date', '<=', sprintf('%04d-12-31', $year));
    }

    // ------------------------------------------------------------ payload

    /** @return array<string, mixed> */
    private function row(string $label, ?float $buku, ?float $spt, ?float $selisih, string $kind, ?string $panel = null): array
    {
        $row = [
            'label' => $label,
            'buku' => $buku === null ? null : round($buku, 2),
            'spt' => $spt === null ? null : round($spt, 2),
            'selisih' => $selisih === null ? null : round($selisih, 2),
            'kind' => $kind,
        ];

        if ($panel !== null) {
            $row['panel'] = $panel;
        }

        return $row;
    }

    /**
     * The residual, printed even when zero — "0" here means TESTED, and the
     * `+ 0.0` keeps a floating-point −0 from printing a minus sign over a
     * clean reconciliation.
     *
     * @return array{label: string, amount: float}
     */
    private function residual(float $amount, string $label = 'Selisih belum terjelaskan'): array
    {
        return ['label' => $label, 'amount' => round($amount, 2) + 0.0];
    }

    /** @return array<string, mixed> */
    private function emptyWorksheet(string $key, string $title, string $message): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'rows' => [],
            'residual' => null,
            'warnings' => [$message],
        ];
    }

    private function rateLabel(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', '.'), '0'), ',');
    }
}
