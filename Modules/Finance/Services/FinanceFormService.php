<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Support\Terbilang;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Models\PaymentWithholding;
use Modules\Finance\Models\PettyCashFund;
use Modules\Finance\Models\TaxObligation;

/**
 * The body of the four Keuangan house forms, in the taste of
 * Modules\Procurement\Services\ProcurementFormService.
 *
 * It exists so Modules\Core\Support\PrintableDocuments stays a DECLARATION.
 * Everything below is a read off the record it was given or arithmetic over
 * that record's own rows; nothing here writes and nothing here invents. Most
 * of it could have been closures in the registry — four answers could not,
 * because each needs a decision spelled out in prose beside it, and a decision
 * that needs prose does not belong in an array literal:
 *
 *   WHO RECEIVED THE MONEY. fin_payments has no recipient column at all. The
 *   name is derivable only from what the payment SETTLED: an AP bill knows its
 *   vendor, an AR invoice its customer. A gl_account allocation — net payroll
 *   on 2-1110, an SSP remittance, BPJS — knows only a liability account, and
 *   "2-1110 Hutang Gaji & Upah" printed as the penerima would name a ledger
 *   line as the person who took the cash. Those rules stay blank. That is not
 *   a gap to be filled later either: one transfer settles forty employees, and
 *   the sheet that says so honestly is the one the bank slip is stapled to.
 *
 *   THE FOOTING OF A VOUCHER JURNAL. The generic sheet prints a totals row in
 *   the LAST column only, so a debit total would land under a heading that
 *   says KREDIT. On a voucher, a figure under the wrong column heading is the
 *   whole failure — so the footing is its own two-column recap, and it prints
 *   the SELISIH as well as both sides. A draft that does not balance has to
 *   say so on the paper; hiding it behind a footing that only shows one side
 *   would defeat the document's only purpose.
 *
 *   AN UNPRICED MASA. fin_tax_obligations.amount is nullable BY DESIGN — the
 *   calendar row is minted for the whole year before anybody knows the money
 *   (TaxObligationService::generate). A register that summed the priced rows
 *   and called it the total would print a figure that is always too small, on
 *   a sheet whose reader is checking whether the year's obligations were paid.
 *   The unpriced masa is ruled, and the total says how many masas it counted.
 *
 *   AN AP BILL'S COST CATEGORY. fin_ap_bills.cost_category is nullable and a
 *   null means "derive it from the source document" — a derivation private to
 *   ApBillService, applied when the journal is posted. Nothing here re-derives
 *   it: a second implementation could disagree with the journal that actually
 *   carries the cost, on the sheet somebody signs to release payment. Stated
 *   or ruled, nothing in between, which is why there is no method for it here.
 */
class FinanceFormService
{
    /**
     * Bulan dalam bahasa Indonesia. The fifth copy in this codebase (see
     * FormPrintService, DocumentPdfService, Support\CalendarEvents and
     * FiscalPeriod) and for their reason: APP_LOCALE is 'en' with no lang/
     * directory, so reaching Carbon's translatedFormat() means switching the
     * whole application locale to 'id' and taking every validation message
     * with it.
     */
    private const MONTHS = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    /**
     * The settled documents of one printed voucher, resolved once — see
     * allocationTargets(), which is where the shape and both halves of the key
     * are explained.
     *
     * @var \WeakMap<Payment, array<string, array<int, array{code: ?string, name: ?string, party: ?string}>>>|null
     */
    private static ?\WeakMap $allocationTargets = null;

    /** What each allocation row settles, in the words the paper uses. */
    private const ALLOCATION_KINDS = [
        PaymentAllocation::TYPE_AP_BILL => 'Tagihan vendor',
        PaymentAllocation::TYPE_AR_INVOICE => 'Faktur penjualan',
        PaymentAllocation::TYPE_GL_ACCOUNT => 'Kewajiban non-AP (akun buku besar)',
        PaymentAllocation::TYPE_PETTY_CASH_FUND => 'Kas kecil (dana imprest)',
    ];

    // -------------------------------------------------------- tagihan vendor

    /**
     * The verification column, in the order a verifier checks it.
     *
     * Every figure is a STORED column that ApBillService computed and saved.
     * None is recomputed: this sheet is signed to release money and the
     * journal that follows it reads the same columns, so the two must agree to
     * the rupiah. The netto's label states the identity ApBillService::recalc
     * enforces (DPP + PPN − PPh − retensi), so a reader can check the column
     * with a pen instead of trusting it.
     *
     * Uang muka sits BELOW the netto rather than inside that identity, because
     * it is a separate netting: an advance already paid against the same PO is
     * consumed when the final bill is approved, and folding it into the
     * equation above would make the arithmetic on the paper stop working.
     *
     * @return list<array<string, mixed>>
     */
    public function billAmountRows(ApBill $bill): array
    {
        return [
            ['uraian' => 'Dasar Pengenaan Pajak (DPP)', 'nilai' => (float) $bill->dpp],
            ['uraian' => 'PPN masukan', 'nilai' => (float) $bill->ppn_amount],
            ['uraian' => 'PPh dipotong perusahaan', 'nilai' => (float) $bill->pph_amount],
            ['uraian' => 'Retensi ditahan', 'nilai' => (float) ($bill->retention_amount ?? 0)],
            ['uraian' => 'NETTO DIBAYARKAN (DPP + PPN − PPh − retensi)', 'nilai' => (float) $bill->total_payable],
            ['uraian' => 'Uang muka yang diperhitungkan', 'nilai' => (float) $bill->advance_applied_amount],
            ['uraian' => 'Sudah dibayar', 'nilai' => (float) $bill->amount_paid],
            // ApBill::outstanding(), never a subtraction written again here:
            // a cancelled bill owes nothing, and only the model knows that.
            ['uraian' => 'Sisa terutang', 'nilai' => $bill->outstanding()],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function billTerbilangRow(ApBill $bill): array
    {
        return [[
            'amount' => (float) $bill->total_payable,
            'terbilang' => Terbilang::rupiah((float) $bill->total_payable),
        ]];
    }

    /**
     * Which goods receipts this bill prices, on either of the two routes that
     * record one — the partial-billing rows and the PO-less accrual.
     *
     * The reference a verifier physically matches the delivery notes against,
     * and ruled on a bill that names none (an advance, a service bill).
     */
    public function billReceiptCodes(ApBill $bill): ?string
    {
        $codes = $bill->billedReceipts
            ->map(fn ($row): ?string => $row->goodsReceipt?->code)
            ->push($bill->goodsReceipt?->code)
            ->filter()
            ->unique()
            ->values();

        return $codes->isEmpty() ? null : $codes->implode(', ');
    }

    // ------------------------------------------------------ bukti pembayaran

    /**
     * The one name on the sheet that fin_payments does not store — see the
     * class docblock.
     *
     * Derived only from the documents the payment settled, and only from the
     * two that name a party. Several counterparties on one payment are all
     * listed rather than silently reduced to the first: a transfer that pays
     * two vendors at once is unusual and is exactly what a reader needs to
     * see. Everything else — a GL liability, a petty-cash drawer — comes back
     * null and the sheet rules the line.
     */
    public function paymentRecipient(Payment $payment): ?string
    {
        $targets = $this->allocationTargets($payment);
        $names = [];

        foreach ($payment->allocations as $allocation) {
            $party = $targets[$allocation->payable_type][(int) $allocation->payable_id]['party'] ?? null;

            if (is_string($party) && trim($party) !== '') {
                $names[trim($party)] = true;
            }
        }

        return $names === [] ? null : implode('; ', array_keys($names));
    }

    /**
     * What this payment settled, one row per allocation.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentAllocationRows(Payment $payment): array
    {
        $targets = $this->allocationTargets($payment);
        $rows = [];
        $no = 0;

        foreach ($payment->allocations as $allocation) {
            $target = $targets[$allocation->payable_type][(int) $allocation->payable_id] ?? [];

            $rows[] = [
                'no' => ++$no,
                'jenis' => self::ALLOCATION_KINDS[$allocation->payable_type] ?? $allocation->payable_type,
                'dokumen' => $target['code'] ?? null,
                'uraian' => $target['name'] ?? null,
                'keterangan' => $allocation->remark,
                'nilai' => (float) $allocation->amount,
            ];
        }

        return $rows;
    }

    /**
     * The deductions that never reached the bank.
     *
     * A withholding row's ALASAN is printed because for OtherDeduction it is
     * the whole audit trail — WithholdingType::requiresReason() refuses the row
     * without one — and a denda taken off a termin with no reason on the paper
     * is a difference nobody can explain a year later.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentDeductionRows(Payment $payment): array
    {
        $rows = [];
        $no = 0;

        foreach ($payment->withholdings as $withholding) {
            /** @var PaymentWithholding $withholding */
            $rows[] = [
                'no' => ++$no,
                'jenis' => $withholding->type?->label(),
                'bukti' => $withholding->certificate_no,
                'tanggal' => $withholding->certificate_date,
                'alasan' => $withholding->reason,
                'nilai' => (float) $withholding->amount,
            ];
        }

        return $rows;
    }

    /**
     * The three figures a bukti pembayaran is read for.
     *
     * Stated as three separate facts rather than one equation, because the
     * allocation sum and the cash CAN legitimately differ by more than the
     * withholdings on a document this sheet has no business editorialising
     * about. Each line says what it counted.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentSummaryRows(Payment $payment): array
    {
        $allocated = round((float) $payment->allocations->sum('amount'), 2);
        $withheld = round((float) $payment->withholdings->sum('amount'), 2);

        return [
            ['uraian' => 'Jumlah dialokasikan ke dokumen', 'nilai' => $allocated],
            ['uraian' => 'Potongan pajak & lain-lain (tidak melalui bank)', 'nilai' => $withheld],
            ['uraian' => 'NILAI KAS / BANK', 'nilai' => (float) $payment->amount],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function paymentTerbilangRow(Payment $payment): array
    {
        return [[
            'amount' => (float) $payment->amount,
            'terbilang' => Terbilang::rupiah((float) $payment->amount),
        ]];
    }

    /**
     * "Bank Central Asia — 5420123456 a.n. PT …", or null when the account row
     * carries nothing beyond its own name.
     */
    public function paymentBankLine(Payment $payment): ?string
    {
        $account = $payment->bankAccount;

        if ($account === null) {
            return null;
        }

        $parts = array_filter([
            trim((string) $account->bank_name),
            trim((string) $account->account_no),
        ], static fn (string $part): bool => $part !== '');

        if ($parts === []) {
            return null;
        }

        $holder = trim((string) $account->account_name);

        return implode(' — ', $parts).($holder === '' ? '' : ' (a.n. '.$holder.')');
    }

    // ------------------------------------------------------- voucher jurnal

    /**
     * Both sides and the difference between them — see the class docblock for
     * why this is a table of its own and not a totals row.
     *
     * A balanced voucher prints "Selisih 0,00", which is a fact and the whole
     * point: the reader is told the two sides were compared, not left to add
     * the column up himself.
     *
     * @return list<array<string, mixed>>
     */
    public function journalRecapRows(Journal $journal): array
    {
        $debit = $journal->totalDebit();
        $credit = $journal->totalCredit();

        return [
            ['uraian' => 'Jumlah debit', 'nilai' => $debit],
            ['uraian' => 'Jumlah kredit', 'nilai' => $credit],
            ['uraian' => 'Selisih (debit − kredit)', 'nilai' => round($debit - $credit, 2)],
        ];
    }

    /** "JV/2026/VIII/0004 — dokumen sumber: ap_bill #12", or null. */
    public function journalReference(Journal $journal): ?string
    {
        $type = trim((string) ($journal->reference_type ?? ''));

        if ($type === '' || $journal->reference_id === null) {
            return null;
        }

        return $type.' #'.$journal->reference_id;
    }

    // ------------------------------------------------------ kewajiban pajak

    /**
     * The masa register of ONE tax year — the year of the row the button was
     * pressed on.
     *
     * A REGISTER IS A LIST and the endpoint hands this one row's id, exactly as
     * the Crm guarantee register does. That is the sheet somebody files: masas
     * fall due one at a time, and what a finance manager needs in front of him
     * is the whole year at once, in the order the deadlines arrive.
     *
     * @return Collection<int, TaxObligation>
     */
    public function taxRegister(TaxObligation $anchor): Collection
    {
        return TaxObligation::query()
            // withTrashed: fin_journals soft-deletes, and the JV number is the
            // only thread from a masa row back to the entry that settled it.
            // Dropping it leaves the register pointing at nothing.
            ->with(['journal' => fn ($query) => $query->withTrashed()])
            ->where('masa_year', $anchor->masa_year)
            ->orderBy('masa_month')
            ->orderBy('tax_type')
            ->get();
    }

    /** @return list<array<string, mixed>> */
    public function taxRegisterRows(TaxObligation $anchor): array
    {
        $rows = [];
        $no = 0;

        foreach ($this->taxRegister($anchor) as $row) {
            $rows[] = [
                'no' => ++$no,
                'jenis' => $row->tax_type?->label(),
                'masa' => $this->monthLabel((int) $row->masa_month, (int) $row->masa_year),
                'uraian' => $row->name,
                'tenggat' => $row->due_date,
                // NULLABLE by design; ruled rather than zeroed. See the class
                // docblock — a masa is minted before its money is known.
                'nilai' => $row->amount === null ? null : (float) $row->amount,
                'ntpn' => $row->ntpn,
                'disetor' => $row->disetor_date,
                'dilapor' => $row->dilapor_date,
                // Derived by the model from those two dates, never a stored
                // column, so a cleared date rolls the status back with it.
                'status' => $row->statusLabel(),
                'jurnal' => $row->journal?->code,
                'catatan' => $row->notes,
            ];
        }

        return $rows;
    }

    /** The value of the masas that HAVE been priced, or null when none has. */
    public function taxRegisterTotal(TaxObligation $anchor): ?float
    {
        $stated = $this->taxRegister($anchor)->filter(
            static fn (TaxObligation $row): bool => $row->amount !== null
        );

        return $stated->isEmpty() ? null : round((float) $stated->sum('amount'), 2);
    }

    /**
     * The label that makes the total readable: how many masas it counted, out
     * of how many the year carries.
     *
     * Printing "Jumlah nilai" alone over a register whose amounts are half
     * unfilled would state a year's tax burden that is always too small.
     */
    public function taxRegisterTotalLabel(TaxObligation $anchor): string
    {
        $register = $this->taxRegister($anchor);
        $stated = $register->filter(static fn (TaxObligation $row): bool => $row->amount !== null)->count();

        return sprintf('Jumlah nilai tercatat (%d dari %d masa sudah dinilai)', $stated, $register->count());
    }

    /**
     * "3 dari 48 masa lewat tenggat", counted as at today.
     *
     * As at the day the sheet is printed, and the label says so, because that
     * is the only date this register has: a masa register is cumulative and
     * carries no period of its own.
     */
    public function taxRegisterOverdue(TaxObligation $anchor): int
    {
        $today = Carbon::today()->startOfDay();

        return $this->taxRegister($anchor)
            ->filter(static fn (TaxObligation $row): bool => $row->status() === 'belum'
                && $row->due_date !== null
                && $row->due_date->lt($today))
            ->count();
    }

    public function taxRegisterCount(TaxObligation $anchor): int
    {
        return $this->taxRegister($anchor)->count();
    }

    public function taxRegisterUnpaid(TaxObligation $anchor): int
    {
        return $this->taxRegister($anchor)
            ->filter(static fn (TaxObligation $row): bool => $row->status() === 'belum')
            ->count();
    }

    // ------------------------------------------------------------- internals

    private function monthLabel(int $month, int $year): string
    {
        return (self::MONTHS[$month] ?? (string) $month).' '.$year;
    }

    /**
     * Every allocation's settled document, resolved in ONE query per kind —
     * and once per printed record, not once per caller.
     *
     * PaymentAllocation::payable() answers for a single row and is right for a
     * screen; a printed voucher settling forty bills would run forty queries
     * inside one print. Same manual "morph" over the short payable keys the
     * column stores — they are not FQCNs and never cross the wire as any.
     *
     * THE MEMO IS PART OF THAT CLAIM, because the sheet asks three times: the
     * PENERIMA identity line and the first signature rule each call
     * paymentRecipient(), and the ALOKASI table calls paymentAllocationRows().
     * Unmemoised, the demo voucher ran fin_ap_bills x3 and prc_vendors x3 for
     * one payment — measured, not assumed — so "one query per kind" was true of
     * the method and false of the document it exists for.
     *
     * KEYED BY THE RECORD OBJECT, in a WeakMap, and both halves matter:
     *
     *  - static, because the registry resolves this service through app() in
     *    every closure and each of the three callers therefore gets a FRESH
     *    instance; a per-instance memo would never once be hit.
     *  - by object rather than by payment id, because a memo that outlived the
     *    record would answer the NEXT print from the LAST one's data. One
     *    request composes one record (FormPrintService::registryDocument reads
     *    it with findOrFail and hands that same instance to every resolver), so
     *    the entry is reachable exactly while the sheet is being composed and
     *    is collected with the record. A voucher reprinted after a bill was
     *    deleted must re-read the bill, not remember it.
     *
     * The boundary stated plainly: a caller that keeps ONE Payment instance
     * alive across a delete and asks again is answered from the first read.
     * That is the sheet's contract — one composition, one set of facts, so the
     * PENERIMA line and the ALOKASI table cannot disagree — and it is why this
     * stays private rather than becoming a cache anything else may reach for.
     *
     * @return array<string, array<int, array{code: ?string, name: ?string, party: ?string}>>
     */
    private function allocationTargets(Payment $payment): array
    {
        self::$allocationTargets ??= new \WeakMap;

        if (! isset(self::$allocationTargets[$payment])) {
            self::$allocationTargets[$payment] = $this->resolveAllocationTargets($payment);
        }

        return self::$allocationTargets[$payment];
    }

    /**
     * @return array<string, array<int, array{code: ?string, name: ?string, party: ?string}>>
     */
    private function resolveAllocationTargets(Payment $payment): array
    {
        $ids = [];

        foreach ($payment->allocations as $allocation) {
            $ids[$allocation->payable_type][] = (int) $allocation->payable_id;
        }

        $targets = [];

        /*
         * withTrashed on every lookup below, and the reason is sharper here
         * than anywhere else in this file: findMany() does not return NULL for
         * a soft-deleted row, it returns NOTHING AT ALL. The allocation row
         * then falls through `$targets[...] ?? []` in paymentAllocationRows()
         * and prints a real rupiah figure with NO document number and NO
         * description beside it — money on a signed voucher that names
         * nothing. fin_ap_bills, fin_ar_invoices, fin_accounts and the petty
         * cash funds all soft-delete.
         *
         * The allocation itself is never dropped either way: WHAT a payment
         * settled is a fact of the payment, not of the settled document's
         * current lifecycle.
         */
        foreach ($ids as $type => $keys) {
            $keys = array_values(array_unique($keys));

            $targets[$type] = match ($type) {
                PaymentAllocation::TYPE_AP_BILL => ApBill::query()->withTrashed()
                    ->with(['vendor' => fn ($query) => $query->withTrashed()])->findMany($keys)
                    ->mapWithKeys(fn (ApBill $bill): array => [$bill->id => [
                        'code' => $bill->code,
                        'name' => $bill->description,
                        'party' => $bill->vendor?->name,
                    ]])->all(),
                PaymentAllocation::TYPE_AR_INVOICE => ArInvoice::query()->withTrashed()
                    ->with(['customer' => fn ($query) => $query->withTrashed()])->findMany($keys)
                    ->mapWithKeys(fn (ArInvoice $invoice): array => [$invoice->id => [
                        'code' => $invoice->code,
                        'name' => $invoice->description,
                        'party' => $invoice->customer?->name,
                    ]])->all(),
                // An account is not a party. Its code and name are printed in
                // the allocation table because that is WHAT was settled; the
                // penerima line above stays ruled, which is the point.
                PaymentAllocation::TYPE_GL_ACCOUNT => Account::query()->withTrashed()->findMany($keys)
                    ->mapWithKeys(fn (Account $account): array => [$account->id => [
                        'code' => $account->code,
                        'name' => $account->name,
                        'party' => null,
                    ]])->all(),
                PaymentAllocation::TYPE_PETTY_CASH_FUND => PettyCashFund::query()->withTrashed()->findMany($keys)
                    ->mapWithKeys(fn (PettyCashFund $fund): array => [$fund->id => [
                        'code' => $fund->code,
                        'name' => $fund->name,
                        'party' => null,
                    ]])->all(),
                default => [],
            };
        }

        return $targets;
    }
}
