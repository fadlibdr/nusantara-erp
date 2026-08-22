<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Company;
use Modules\Core\Support\Erp;
use Modules\Core\Support\Money;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Support\BuktiPotongNumber;

/**
 * Statutory tax reporting exports.
 *
 *   PPN keluaran  -> e-Faktur (faktur pajak keluaran, from approved AR invoices)
 *   PPh dipotong  -> e-Bupot Unifikasi (bukti potong, from approved AP bills)
 *
 * WHAT THIS IS AND IS NOT
 * -----------------------
 * These build the CSV a tax officer feeds to the DJP application; they do not
 * talk to DJP. There is no filing, no signature and no submission — the output
 * is a file a human reviews and imports.
 *
 * VERIFY THE LAYOUT BEFORE PRODUCTION USE. The column order below follows the
 * long-standing e-Faktur desktop import schema (FK / LT / OF records), which
 * Coretax has been progressively replacing since 2025. DJP revises these layouts,
 * and an importer that rejects a file is a good day — one that accepts a file
 * with columns shifted is not. Import one period into a sandbox and reconcile
 * the totals before trusting a run. This mirrors how config/erp.php treats the
 * PPh 21 TER brackets: transcribed carefully, and flagged as needing checking
 * against the current regulation.
 *
 * WHAT IS DELIBERATELY NOT GUESSED
 * --------------------------------
 * Kode objek pajak is read from the tax master row (fin_taxes.object_code), not
 * hard-coded. A tax with no code is reported as a blocker rather than exported
 * with a plausible-looking guess, because a bukti potong filed against the wrong
 * object is a correction, not an error message.
 *
 * SKIPPED ROWS ARE REPORTED, NEVER DROPPED
 * ----------------------------------------
 * An invoice with no faktur pajak number, or a counterparty with no NPWP, cannot
 * be exported. Both are returned in `blockers` with the document code and the
 * reason, so a short file is visibly short rather than quietly wrong.
 *
 * BOTH EXPORTS ARE READ-ONLY
 * --------------------------
 * eFaktur(), eBupot() and overview() write nothing. They are reached by GET on
 * fin.view, and PeriodCloseService::itemTaxExportReady() calls overview() every
 * time a closer merely LOOKS at the checklist. The one thing that used to be
 * written from here — the nomor bukti potong — is minted at bill approval, or
 * by issueBuktiPotongNumbers() for a masa whose bills predate that column; both
 * are explicit acts by someone holding fin.approve. A bill with no number is a
 * blocker, never a silent side effect.
 *
 * DPP NILAI LAIN (PMK 131/2024)
 * -----------------------------
 * The invoice carries the harga jual and an EFFECTIVE ppn_rate of 11 (see
 * config/erp.php: "tarif resmi 12% dikenakan atas DPP nilai lain — 11/12 dari
 * harga jual"). A faktur for a non-luxury BKP/JKP must state that DPP nilai
 * lain, with PPN at the statutory 12 % of it — not the harga jual with PPN at
 * 11 %. The rupiah of PPN is identical either way (11 % of harga == 12 % of
 * 11/12 harga), which is why the ledger, the invoice total and the cash are all
 * correct and only the reported DPP was wrong: Rp 9.700.000.000 written where
 * the faktur should say Rp 8.891.666.666,67, i.e. 9,09 % too high on exactly
 * the figure an equalisasi compares against turnover. The DPP is therefore
 * derived from the PPN the invoice actually charges, which keeps a luxury-goods
 * invoice carrying the headline 12 % rate reporting its harga jual unchanged.
 * The rows and the summary carry BOTH figures — `dpp` is the harga jual the
 * ledger holds, `dpp_faktur` is what goes in the file.
 */
class TaxExportService
{
    /** e-Faktur separates records by comma and quotes nothing; commas in names are stripped. */
    private const SEPARATOR = ',';

    /**
     * Faktur pajak keluaran for one tax period.
     *
     * @return array<string, mixed>
     */
    public function eFaktur(int $year, int $month): array
    {
        $this->assertPeriod($year, $month);

        $company = Company::current();
        $period = Carbon::create($year, $month, 1);

        $invoices = ArInvoice::query()
            ->with('customer')
            ->where('status', DocumentStatus::Approved->value)
            ->whereYear('invoice_date', $year)
            ->whereMonth('invoice_date', $month)
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        $rows = [];
        $blockers = [];
        $lines = [$this->eFakturHeader()];

        foreach ($invoices as $invoice) {
            $reason = $this->eFakturBlocker($invoice);

            if ($reason !== null) {
                $blockers[] = [
                    'document' => $invoice->code,
                    'partner' => $invoice->customer?->name,
                    'dpp' => round((float) $invoice->dpp, 2),
                    'ppn' => round((float) $invoice->ppn_amount, 2),
                    'reason' => $reason,
                ];

                continue;
            }

            $faktur = $this->splitFakturNumber((string) $invoice->faktur_pajak_no);
            $customer = $invoice->customer;
            $dppFaktur = $this->fakturDpp($invoice);

            $rows[] = [
                'document' => $invoice->code,
                'faktur_pajak_no' => $invoice->faktur_pajak_no,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'partner' => $customer->name,
                'npwp' => $this->digits($customer->npwp),
                'dpp' => round((float) $invoice->dpp, 2),
                'dpp_faktur' => $dppFaktur,
                'ppn' => round((float) $invoice->ppn_amount, 2),
            ];

            // FK — faktur header.
            $lines[] = $this->row([
                'FK',
                $faktur['transaction_code'],
                $faktur['replacement_flag'],
                $faktur['serial'],
                $month,
                $year,
                $invoice->invoice_date?->format('d/m/Y'),
                $this->digits($customer->npwp),
                $this->clean($customer->name),
                $this->clean($customer->billing_address),
                $this->amount($dppFaktur),           // JUMLAH_DPP — nilai lain, see fakturDpp()
                $this->amount($invoice->ppn_amount),
                0,                                   // PPnBM: construction and ICT services carry none
                '',                                  // ID keterangan tambahan
                0,                                   // FG uang muka
                0, 0, 0,                             // uang muka DPP / PPN / PPnBM
                $this->clean($invoice->code),        // referensi — our own document number
                '',                                  // kode dokumen pendukung
            ]);

            // LT — counterparty address. The ERP holds one address string rather
            // than DJP's decomposed fields, so it goes in JALAN and the rest are
            // left empty for the officer to complete if the importer demands it.
            $lines[] = $this->row([
                'LT',
                $this->digits($customer->npwp),
                $this->clean($customer->name),
                $this->clean($customer->billing_address),
                '', '', '', '', '', '',
                $this->clean($customer->city),
                $this->clean($customer->province),
                '',
                $this->clean($customer->phone),
            ]);

            // OF — one line for the whole termin. The invoice bills a contract
            // stage, not a bill of materials, so a single service line is the
            // faithful representation.
            $lines[] = $this->row([
                'OF',
                '',                                  // kode objek — not used for services
                $this->clean($invoice->description),
                $this->amount($invoice->dpp),        // harga satuan — harga jual
                1,                                   // jumlah barang
                $this->amount($invoice->dpp),        // harga total — harga jual
                0,                                   // diskon
                $this->amount($dppFaktur),           // DPP — nilai lain
                $this->amount($invoice->ppn_amount),
                0, 0,                                // tarif PPnBM, PPnBM
            ]);
        }

        return [
            'kind' => 'e-faktur',
            'period' => $this->periodMeta($period),
            'company' => $this->companyMeta($company),
            'columns' => ['document', 'faktur_pajak_no', 'invoice_date', 'partner', 'npwp', 'dpp', 'ppn'],
            'rows' => $rows,
            'blockers' => $blockers,
            'summary' => [
                'exported' => count($rows),
                'blocked' => count($blockers),
                'dpp' => round(array_sum(array_column($rows, 'dpp')), 2),
                'dpp_faktur' => round(array_sum(array_column($rows, 'dpp_faktur')), 2),
                'ppn' => round(array_sum(array_column($rows, 'ppn')), 2),
            ],
            'filename' => sprintf('efaktur-%04d-%02d.csv', $year, $month),
            'csv' => implode("\n", $lines)."\n",
        ];
    }

    /**
     * Bukti potong (PPh dipotong) for one tax period, from AP bills that
     * withheld tax — PPh 23 on services and PPh final Pasal 4(2) on subcontracted
     * construction work.
     *
     * @return array<string, mixed>
     */
    public function eBupot(int $year, int $month): array
    {
        $this->assertPeriod($year, $month);

        $company = Company::current();
        $period = Carbon::create($year, $month, 1);

        $bills = ApBill::query()
            // withTrashed on the tax: the withholding was made under that
            // scheme and is reported under it, whatever later happened to the
            // master row. Without it, deleting a tax silently turned every
            // historic bukti potong into a blocker naming a remedy the operator
            // cannot perform on an approved bill.
            ->with(['vendor', 'pphTax' => fn ($query) => $query->withTrashed()])
            ->where('status', DocumentStatus::Approved->value)
            ->where('pph_amount', '>', 0)
            ->whereYear('bill_date', $year)
            ->whereMonth('bill_date', $month)
            ->orderBy('bill_date')
            ->orderBy('id')
            ->get();

        $rows = [];
        $blockers = [];
        $lines = [$this->eBupotHeader()];

        foreach ($bills as $bill) {
            $reason = $this->eBupotBlocker($bill);

            if ($reason !== null) {
                $blockers[] = [
                    'document' => $bill->code,
                    'partner' => $bill->vendor?->name,
                    'dpp' => round((float) $bill->dpp, 2),
                    'pph' => round((float) $bill->pph_amount, 2),
                    'reason' => $reason,
                ];

                continue;
            }

            $vendor = $bill->vendor;
            $tax = $bill->pphTax;
            $dpp = round((float) $bill->dpp, 2);
            $pph = round((float) $bill->pph_amount, 2);

            // The effective rate, not the master rate: a bill may have been
            // withheld at a rate that has since been revised, and the bukti
            // potong must report what was actually deducted.
            $rate = $dpp > 0 ? round($pph / $dpp * 100, 4) : 0.0;
            $slipNumber = $this->buktiPotongNumber($bill);

            $rows[] = [
                'slip_no' => $slipNumber,
                'document' => $bill->code,
                'bill_date' => $bill->bill_date?->toDateString(),
                'partner' => $vendor->name,
                'npwp' => $this->digits($vendor->npwp),
                'tax_code' => $tax->code,
                'object_code' => $tax->object_code,
                'dpp' => $dpp,
                'rate' => $rate,
                'pph' => $pph,
            ];

            $lines[] = $this->row([
                $slipNumber,
                $month,
                $year,
                $this->digits($vendor->npwp),
                $this->clean($vendor->name),
                $this->clean($vendor->address),
                $tax->object_code,
                $this->clean($tax->name),
                $this->amount($dpp),
                $this->number($rate),
                $this->amount($pph),
                $bill->bill_date?->format('d/m/Y'),
                $this->clean($bill->code),
                $this->clean($bill->description),
            ]);
        }

        return [
            'kind' => 'e-bupot',
            'period' => $this->periodMeta($period),
            'company' => $this->companyMeta($company),
            'columns' => ['slip_no', 'document', 'bill_date', 'partner', 'npwp', 'tax_code', 'object_code', 'dpp', 'rate', 'pph'],
            'rows' => $rows,
            'blockers' => $blockers,
            'summary' => [
                'exported' => count($rows),
                'blocked' => count($blockers),
                'dpp' => round(array_sum(array_column($rows, 'dpp')), 2),
                'pph' => round(array_sum(array_column($rows, 'pph')), 2),
            ],
            'filename' => sprintf('ebupot-%04d-%02d.csv', $year, $month),
            'csv' => implode("\n", $lines)."\n",
        ];
    }

    /**
     * Both exports plus the company's own tax identity, for the screen that
     * offers them side by side.
     *
     * @return array<string, mixed>
     */
    public function overview(int $year, int $month): array
    {
        return [
            'efaktur' => $this->eFaktur($year, $month),
            'ebupot' => $this->eBupot($year, $month),
        ];
    }

    /* -------------------------------------------------------------- amounts */

    /**
     * DPP as the faktur must state it under PMK 131/2024: the nilai lain the
     * statutory 12 % is charged on, not the harga jual.
     *
     * Derived from the PPN the invoice actually charges rather than by
     * multiplying by 11/12, so the two always agree to the rupiah and an
     * invoice raised at the headline rate (a luxury BKP, ppn_rate 12) reports
     * its harga jual unchanged. Worked through on the demo's own INV/2026/II/0001:
     * PPN Rp 1.067.000.000 / 12 % = DPP Rp 8.891.666.666,67, which is 11/12 of
     * the Rp 9.700.000.000 harga jual — the figure the file used to carry.
     *
     * An invoice charging no PPN never reaches here (eFakturBlocker refuses it),
     * so the harga-jual fallback below only covers a misconfigured headline
     * rate: it degrades to the old behaviour rather than dividing by zero.
     */
    private function fakturDpp(ArInvoice $invoice): float
    {
        $headlineRate = Erp::float('tax.ppn_headline_rate', 12.0);
        $ppn = round((float) $invoice->ppn_amount, 2);

        if ($headlineRate <= 0.0 || $ppn <= 0.0) {
            return round((float) $invoice->dpp, 2);
        }

        return round($ppn * 100 / $headlineRate, 2);
    }

    /* ---------------------------------------------------------- penomoran */

    /**
     * Terbitkan nomor bukti potong untuk satu masa pajak.
     *
     * THE EXPORT DOES NOT MINT. It used to: buktiPotongNumber() allocated a
     * number and wrote fin_ap_bills.bupot_no during eBupot(), which is a GET
     * gated on fin.view — and PeriodCloseService::itemTaxExportReady() runs the
     * same export, so merely PREVIEWING a close bound permanent legal reference
     * numbers to bills and consumed counters from the 'BP-YYYYMM' sequence.
     * Reading a report may not write business data.
     *
     * The numbers still have to be stable and persistent — that was the real
     * defect behind the fix that introduced the write, since positional
     * numbering renumbered every certificate on re-run — so they are minted at
     * exactly two moments, both of them explicit acts:
     *
     *   approval   ApBillService::approve() mints one for every bill that
     *              withholds, which is when the withholding becomes a fact and
     *              the bill stops being editable. This covers everything keyed
     *              from now on;
     *   this call  the one-off catch-up for bills approved BEFORE the column
     *              existed (migration 2026_08_02_001112). On the live demo that
     *              population is empty today — 0 approved bills with pph > 0 and
     *              no bupot_no — but a restored backup or an older tenant will
     *              have them, and without this they would be permanently
     *              unexportable now that the export no longer numbers them.
     *
     * Numbering is deliberately NOT conditional on the row being exportable.
     * Approval mints regardless of whether the vendor's NPWP is on file, so a
     * catch-up that skipped blocked rows would hand a bill a number out of the
     * order it would have had, and the certificate that was blocked last masa
     * would take a number a later bill already carries. Every unnumbered
     * approved withholding of the masa is numbered here, oldest bill first.
     *
     * @return array<string, mixed>
     */
    public function issueBuktiPotongNumbers(int $year, int $month): array
    {
        $this->assertPeriod($year, $month);

        $period = Carbon::create($year, $month, 1);

        return DB::transaction(function () use ($year, $month, $period): array {
            $bills = ApBill::query()
                ->with('vendor')
                ->where('status', DocumentStatus::Approved->value)
                ->where('pph_amount', '>', 0)
                ->whereYear('bill_date', $year)
                ->whereMonth('bill_date', $month)
                ->orderBy('bill_date')
                ->orderBy('id')
                ->get();

            $issued = [];
            $numbered = 0;

            foreach ($bills as $bill) {
                if (filled($bill->bupot_no)) {
                    // Already carries its number; re-running this changes nothing.
                    $numbered++;

                    continue;
                }

                $number = BuktiPotongNumber::allocate($year, $month);
                $bill->forceFill(['bupot_no' => $number])->save();

                $issued[] = [
                    'slip_no' => $number,
                    'document' => $bill->code,
                    'partner' => $bill->vendor?->name,
                    'dpp' => round((float) $bill->dpp, 2),
                    'pph' => round((float) $bill->pph_amount, 2),
                ];
            }

            return [
                'kind' => 'e-bupot-numbers',
                'period' => $this->periodMeta($period),
                'columns' => ['slip_no', 'document', 'partner', 'dpp', 'pph'],
                'rows' => $issued,
                'summary' => [
                    'issued' => count($issued),
                    'already_numbered' => $numbered,
                ],
            ];
        });
    }

    /**
     * The bill's own nomor bukti potong, read and never written.
     *
     * A bill with no number never reaches here — eBupotBlocker() reports it as
     * a blocker instead, so a certificate is either exported with the number it
     * has carried since approval or reported as not yet issued. Re-running a
     * masa therefore reproduces the identical file, and nothing about running
     * the report changes what the next run will say.
     */
    private function buktiPotongNumber(ApBill $bill): string
    {
        return (string) $bill->bupot_no;
    }

    /* ------------------------------------------------------------ blockers */

    private function eFakturBlocker(ArInvoice $invoice): ?string
    {
        if (blank($invoice->faktur_pajak_no)) {
            return 'Nomor faktur pajak belum diisi — catat nomor seri dari DJP pada invoice ini.';
        }

        $customer = $invoice->customer;

        if ($customer === null) {
            return 'Pelanggan tidak ditemukan.';
        }

        if (strlen($this->digits($customer->npwp)) < 15) {
            return "NPWP pelanggan {$customer->name} belum diisi atau tidak lengkap.";
        }

        if (round((float) $invoice->ppn_amount, 2) <= 0.0) {
            return 'Invoice tidak memungut PPN, jadi tidak ada faktur pajak keluaran.';
        }

        return null;
    }

    private function eBupotBlocker(ApBill $bill): ?string
    {
        $vendor = $bill->vendor;

        if ($vendor === null) {
            return 'Vendor tidak ditemukan.';
        }

        if ($bill->pphTax === null) {
            // Two different problems, and only one of them is the operator's:
            // a bill that never named its tax can still be fixed while it is a
            // draft, whereas a pph_tax_id pointing at a row that no longer
            // exists is master data to restore, not a field to fill in.
            return $bill->pph_tax_id === null
                ? "Jenis PPh pada {$bill->code} belum ditetapkan — pilih jenis pajaknya pada tagihan."
                : "Jenis PPh pada {$bill->code} menunjuk baris master pajak yang sudah tidak ada — "
                    .'pulihkan baris pajaknya di Master Data › Pajak.';
        }

        if (blank($bill->pphTax->object_code)) {
            return "Kode objek pajak untuk {$bill->pphTax->code} belum diisi — lengkapi di Master Data › Pajak.";
        }

        if (strlen($this->digits($vendor->npwp)) < 15) {
            return "NPWP vendor {$vendor->name} belum diisi atau tidak lengkap.";
        }

        // Last, because it is the only blocker this report cannot ask the
        // operator to fix on the bill itself. Every bill approved since the
        // number existed carries one; a bill that predates it is reported here
        // rather than numbered on the spot, because this export is a GET.
        if (blank($bill->bupot_no)) {
            return "Nomor bukti potong untuk {$bill->code} belum diterbitkan — terbitkan nomor "
                .'bukti potong masa ini lebih dulu (sekali saja; nomor yang sudah terbit tidak berubah).';
        }

        return null;
    }

    /* -------------------------------------------------------------- format */

    private function eFakturHeader(): string
    {
        // Three record types in one file, so the header names all three shapes.
        return implode("\n", [
            $this->row(['FK', 'KD_JENIS_TRANSAKSI', 'FG_PENGGANTI', 'NOMOR_FAKTUR', 'MASA_PAJAK', 'TAHUN_PAJAK', 'TANGGAL_FAKTUR', 'NPWP', 'NAMA', 'ALAMAT_LENGKAP', 'JUMLAH_DPP', 'JUMLAH_PPN', 'JUMLAH_PPNBM', 'ID_KETERANGAN_TAMBAHAN', 'FG_UANG_MUKA', 'UANG_MUKA_DPP', 'UANG_MUKA_PPN', 'UANG_MUKA_PPNBM', 'REFERENSI', 'KODE_DOKUMEN_PENDUKUNG']),
            $this->row(['LT', 'NPWP', 'NAMA', 'JALAN', 'BLOK', 'NOMOR', 'RT', 'RW', 'KECAMATAN', 'KELURAHAN', 'KABUPATEN', 'PROPINSI', 'KODE_POS', 'NOMOR_TELEPON']),
            $this->row(['OF', 'KODE_OBJEK', 'NAMA', 'HARGA_SATUAN', 'JUMLAH_BARANG', 'HARGA_TOTAL', 'DISKON', 'DPP', 'PPN', 'TARIF_PPNBM', 'PPNBM']),
        ]);
    }

    private function eBupotHeader(): string
    {
        return $this->row([
            'NOMOR_BUKTI_POTONG', 'MASA_PAJAK', 'TAHUN_PAJAK', 'NPWP', 'NAMA', 'ALAMAT',
            'KODE_OBJEK_PAJAK', 'NAMA_OBJEK_PAJAK', 'DPP', 'TARIF', 'PPH_DIPOTONG',
            'TANGGAL_PEMOTONGAN', 'REFERENSI', 'KETERANGAN',
        ]);
    }

    /**
     * `010.000-26.00000001` splits into transaction code `01`, replacement flag
     * `0` and the 13-digit serial `0002600000001`. A number that does not match
     * that shape is passed through as digits so the importer, not this service,
     * decides it is wrong.
     *
     * @return array{transaction_code: string, replacement_flag: string, serial: string}
     */
    private function splitFakturNumber(string $faktur): array
    {
        $digits = $this->digits($faktur);

        if (strlen($digits) < 16) {
            return ['transaction_code' => '', 'replacement_flag' => '', 'serial' => $digits];
        }

        return [
            'transaction_code' => substr($digits, 0, 2),
            'replacement_flag' => substr($digits, 2, 1),
            'serial' => substr($digits, 3),
        ];
    }

    private function row(array $values): string
    {
        return implode(self::SEPARATOR, $values);
    }

    /** Amounts go in without separators or decimals — e-Faktur wants whole rupiah. */
    private function amount(mixed $value): string
    {
        return (string) (int) round((float) $value);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    /**
     * The separator is a bare comma and nothing is quoted, so a comma or a
     * newline inside a value would shift every column after it. Both become a
     * space, and runs of whitespace collapse so "PT Graha, Sentosa" reads as
     * "PT Graha Sentosa" rather than keeping the gap the comma left behind.
     */
    private function clean(?string $value): string
    {
        $flattened = preg_replace('/[\r\n,]+/', ' ', (string) $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $flattened) ?? '');
    }

    private function assertPeriod(int $year, int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new LogicException('Masa pajak harus 1-12.');
        }

        if ($year < 2000 || $year > 2100) {
            throw new LogicException('Tahun pajak di luar rentang yang wajar.');
        }
    }

    private function periodMeta(Carbon $period): array
    {
        return [
            'year' => (int) $period->year,
            'month' => (int) $period->month,
            'label' => $period->translatedFormat('F Y'),
        ];
    }

    private function companyMeta(?Company $company): array
    {
        return [
            'name' => $company?->name,
            'npwp' => $company?->npwp,
            'is_pkp' => (bool) $company?->is_pkp,
            'sppkp_number' => $company?->sppkp_number,
        ];
    }

    /**
     * Human-readable one-liner for a summary, used by the console command.
     */
    public function describe(array $export): string
    {
        $s = $export['summary'];
        $value = $export['kind'] === 'e-faktur' ? $s['ppn'] : $s['pph'];

        return sprintf(
            '%s %s: %d dokumen, %s%s',
            $export['kind'],
            $export['period']['label'],
            $s['exported'],
            Money::format($value),
            $s['blocked'] > 0 ? sprintf(' (%d dokumen tertahan)', $s['blocked']) : '',
        );
    }
}
