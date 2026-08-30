<?php

namespace Modules\Finance\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Enums\TaxType;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ApBillGoodsReceipt;
use Modules\Finance\Models\Kasbon;
use Modules\Finance\Models\Tax;
use Modules\Finance\Support\BuktiPotongNumber;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\WorkOrderBilling;
use Modules\Subcontract\Models\LaborClaim;
use Modules\Subcontract\Models\ProgressClaim;

/**
 * AP bills (tagihan vendor):
 *
 *   total_payable = dpp + ppn - pph_withheld
 *
 * Follow one material purchase all the way through — advance, receipt, invoice,
 * consumption — and where the value sits at each step:
 *
 *   UANG MUKA (optional, is_advance = true)
 *       Dr 1-1500 Uang Muka Proyek        dpp uang muka
 *       Dr 1-1600 PPN Masukan             ppn uang muka
 *       Cr 2-1100 Hutang Usaha            total_payable
 *     A prepaid ASSET. No goods exist yet, so there is nothing to receive and
 *     the goods-received gate does not apply; PaymentService can settle it like
 *     any approved bill, which is what makes the standard 20–30 % DP against a
 *     material PO recordable at all. It books NO project cost.
 *
 *   GOODS RECEIPT (Inventory)
 *       Dr 1-1400 Persediaan / Cr <clearing liability>
 *     StockService writes the account it credited and the amount onto the
 *     receipt row. That record is the only thing this service may clear.
 *
 *   FINAL BILL — THREE-WAY MATCH (any bill whose receipts recorded a clearing
 *   credit: a PO bill, or a manual bill against one PO-less goods receipt)
 *       Dr <clearing liability>                nilai yang benar-benar dikredit
 *       Dr/Cr 6-4500 Selisih Harga Pembelian   gross dpp - nilai tersebut
 *       Dr 1-1600 PPN Masukan                  ppn
 *       Cr 1-1500 Uang Muka Proyek             uang muka yang diperhitungkan
 *       Cr 2-1100 Hutang Usaha                 total_payable
 *       Cr 2-12xx Hutang PPh                   pph
 *     where gross dpp = this bill's dpp + the approved advance it consumes, i.e.
 *     the full value of the goods being invoiced. Balanced by construction:
 *     clearing + variance + ppn = gross dpp + ppn = advance + total_payable + pph.
 *     Such a bill records no MATERIAL project cost — that happens on the
 *     material issue — but the price variance IS a project cost: it is charged
 *     to a project in the GL, so it is written to fin_project_costs too, or the
 *     project P&L would understate the purchase by exactly that difference.
 *
 *   CLASSIC TREATMENT — services PO, subcon claim, manual bill, or any bill
 *   whose receipts recorded no clearing credit (periodic inventory, or no
 *   receipt at all)
 *       Dr 5-xxxx Beban / 1-1400 Persediaan   gross dpp  (account by cost source)
 *       Dr 1-1600 PPN Masukan                 ppn
 *       Cr 1-1500 Uang Muka Proyek            uang muka yang diperhitungkan
 *       Cr 2-1100 Hutang Usaha                total_payable
 *       Cr 2-12xx Hutang PPh                  pph
 *     With a project, the gross DPP lands in the project cost ledger.
 *     1-1400 Persediaan is the debit ONLY when the purchase is genuinely stock
 *     (a PO line naming an inventory item) AND perpetual inventory is off, i.e.
 *     when no goods receipt has debited persediaan and none ever will. Every
 *     other non-project bill — a rental, a service, or a stock PO under
 *     perpetual whose goods never arrived — is an expense (6-4100): there is no
 *     stock sub-ledger row behind it that an asset balance could represent.
 *
 *   MATERIAL ISSUE (Inventory)
 *       Dr 5-xxxx Beban proyek / Cr 1-1400 Persediaan
 *     The only step that turns the asset into cost.
 *
 * WHICH TREATMENT APPLIES IS NOT RE-DERIVED HERE. It is read off the receipts:
 * a bill clears exactly the (account, amount) pairs posted goods receipts
 * recorded, minus whatever earlier bills already cleared. The PO's deliver-to
 * warehouse is never consulted — a services PO raised from a PR that named a
 * warehouse has no such receipt and bills classically — and neither is the
 * current value of accounting.perpetual_inventory: toggling it cannot strand a
 * credit the engine raised, nor debit one it never raised, because only what was
 * actually credited can be cleared.
 */
class ApBillService
{
    /** Retention withheld from subcontractors, owed back on release. */
    private const SUBCON_RETENTION_ACCOUNT = '2-1500';

    /**
     * Piutang Karyawan — the receivable a kasbon issue debits (KasbonService
     * uses the same literal). A mandor wage bill's kasbon deduction credits
     * it back out: the advance was recovered by short-paying the wages.
     */
    private const EMPLOYEE_ADVANCE_ACCOUNT = '1-1370';

    private const DEFAULT_PURCHASE_VARIANCE_ACCOUNT = '6-4500';

    /**
     * Uang Muka Proyek — the prepaid asset an advance bill debits and the final
     * bill credits back out. Overridable through accounting.purchase_advance_account.
     */
    private const DEFAULT_PURCHASE_ADVANCE_ACCOUNT = '1-1500';

    /**
     * Persediaan Material, the account the perpetual engine carries stock in.
     * Read through accounting.inventory_account so a periodic-inventory bill
     * capitalises into the SAME account StockService would have used.
     */
    private const DEFAULT_INVENTORY_ACCOUNT = '1-1400';

    /**
     * Beban operasional lain — where a non-project purchase lands when it is
     * not stock the company still holds.
     */
    private const DEFAULT_GENERAL_EXPENSE_ACCOUNT = '6-4100';

    /**
     * Placeholder for the PPh leg of a bill that withholds nothing; autoPost
     * drops a Rp 0 leg before it ever resolves the account. NOT a fallback for
     * an unidentified withholding — see pphLiabilityAccountCode().
     */
    private const DEFAULT_PPH_LIABILITY_ACCOUNT = '2-1220';

    public function __construct(
        private readonly JournalService $journals,
        private readonly ProjectCostService $projectCosts,
    ) {}

    /**
     * Store entry point: from a PO, from a PO-less goods receipt, from a subcon
     * claim when the reference id is given, manual otherwise.
     */
    public function create(array $data): ApBill
    {
        if (! empty($data['purchase_order_id'])) {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()->findOrFail($data['purchase_order_id']);

            return $this->createFromPo($po, $data);
        }

        if (! empty($data['goods_receipt_id'])) {
            return $this->createFromGoodsReceipt((int) $data['goods_receipt_id'], $data);
        }

        if (! empty($data['subcontract_claim_id'])) {
            /** @var ProgressClaim $claim */
            $claim = ProgressClaim::query()->findOrFail($data['subcontract_claim_id']);

            return $this->createFromSubconClaim($claim, $data);
        }

        if (! empty($data['labor_claim_id'])) {
            /** @var LaborClaim $claim */
            $claim = LaborClaim::query()->findOrFail($data['labor_claim_id']);

            return $this->createFromLaborClaim($claim, $data);
        }

        if (! empty($data['work_order_billing_id'])) {
            /** @var WorkOrderBilling $billing */
            $billing = WorkOrderBilling::query()->findOrFail($data['work_order_billing_id']);

            return $this->createFromWorkOrderBilling($billing, $data);
        }

        return $this->createManual($data);
    }

    /**
     * Bill a vendor invoice against an approved PO.
     *
     * Two shapes, chosen by $options['is_advance']:
     *
     *   ADVANCE (uang muka)  the caller states the down-payment DPP; PPN follows
     *       the PO's own rate unless given. At most one advance per PO, and it
     *       must be raised before the final bill.
     *   FINAL                amounts copy the PO commercial terms MINUS any
     *       approved advance, so total_payable is what the vendor is still owed
     *       (pelunasan) and the netting at approval balances against it.
     *
     * WITHHOLDING IS THE CALLER'S, NOT THIS METHOD'S. It used to hard-set
     * pph_tax_id = null / pph_amount = 0 on the assumption that every PO buys
     * goods, while ApBillStoreRequest validated both fields and the form
     * collected them — so a cleaning/consultancy/crane-with-operator PO of
     * Rp 115.600.000 billed with "PPh 23 Jasa" selected produced a bill payable
     * at Rp 128.316.000 instead of Rp 126.004.000: Rp 2.312.000 of statutory
     * withholding paid to the vendor instead of to the state, 2-1220 never
     * credited, and no bukti potong for the masa. This file already asserts
     * services POs are real (see debitAccountCode and
     * assertStockCommitmentSettled, both rewritten around "a rental or a
     * service PO has no stock line").
     *
     * The base for the derive-from-rate convenience on a FINAL bill is the
     * GROSS DPP — this bill plus the approved uang muka it consumes — because
     * PPh is withheld on the whole jumlah bruto of the service, and the advance
     * itself withholds nothing. An uang muka refuses withholding outright: it
     * buys no work yet, books an asset rather than a cost, and the final bill
     * carries the whole withholding for the order.
     *
     * TAGIHAN PARSIAL (#40): $options['goods_receipt_ids'] names specific
     * POSTED receipts of this PO, and the bill then covers exactly those
     * deliveries instead of the whole order — priced at received qty x the PO
     * unit price (the surat jalan invoiced at the negotiated price), less a
     * progressive share of the header discount and of the approved uang muka.
     * The named receipts are written to fin_ap_bill_goods_receipts, whose
     * unique index is what makes "this delivery is already invoiced" hold even
     * against two clerks racing. The two modes are mutually exclusive per PO —
     * a whole-PO bill after partial bills would price and sweep deliveries the
     * partial bills already claimed, and vice versa.
     */
    public function createFromPo(PurchaseOrder $po, array $options = []): ApBill
    {
        return DB::transaction(function () use ($po, $options): ApBill {
            if ($po->status !== DocumentStatus::Approved && $po->status !== DocumentStatus::Closed) {
                throw new LogicException(
                    "PO {$po->code} is {$po->status->value}; only approved POs can be billed."
                );
            }

            $isAdvance = (bool) ($options['is_advance'] ?? false);
            $billDate = $options['bill_date'] ?? now()->toDateString();
            $receiptIds = array_values(array_unique(array_map(
                'intval',
                (array) ($options['goods_receipt_ids'] ?? []),
            )));

            if ($isAdvance && $receiptIds !== []) {
                // A DP buys no delivery, so it cannot claim one: the claim rows
                // would block the real invoice for those goods for ever.
                throw new LogicException(
                    "Uang muka atas {$po->code} tidak menunjuk penerimaan barang; "
                    .'buat tagihan parsialnya tanpa menandai uang muka.'
                );
            }

            if ($receiptIds !== []) {
                return $this->createPartialFromPo($po, $receiptIds, $options, $billDate);
            }

            if ($isAdvance) {
                if (($options['pph_tax_id'] ?? null) !== null
                    || round((float) ($options['pph_amount'] ?? 0), 2) > 0.0) {
                    throw new LogicException(
                        "Uang muka atas {$po->code} tidak memotong PPh; potong PPh pada tagihan finalnya."
                    );
                }

                [$dpp, $ppn] = $this->advanceAmounts($po, $options);
                [$pphTaxId, $pphAmount] = [null, 0.0];
            } else {
                [$dpp, $ppn] = $this->finalBillAmounts($po);
                [$pphTaxId, $pphAmount] = $this->resolvePph(
                    $options,
                    round($dpp + $this->approvedAdvanceTotalsFor((int) $po->id)['dpp'], 2),
                );
            }

            return $this->build([
                'vendor_id' => (int) $po->vendor_id,
                'project_id' => $po->project_id,
                'purchase_order_id' => (int) $po->id,
                'goods_receipt_id' => null,
                'subcontract_claim_id' => null,
                'is_advance' => $isAdvance,
                'bill_date' => $billDate,
                'due_date' => $options['due_date']
                    ?? Carbon::parse($billDate)->addDays((int) $po->payment_term_days)->toDateString(),
                'description' => $options['description'] ?? ($isAdvance
                    ? "Uang muka atas {$po->code}"
                    : "Tagihan vendor atas {$po->code}"),
                'cost_category' => $options['cost_category'] ?? null,
                'dpp' => $dpp,
                'ppn_amount' => $ppn,
                'pph_tax_id' => $pphTaxId,
                'pph_amount' => $pphAmount,
                'vendor_invoice_no' => $options['vendor_invoice_no'] ?? '',
                'faktur_pajak_no' => $options['faktur_pajak_no'] ?? null,
            ]);
        });
    }

    /**
     * Tagihan parsial: bill exactly the named POSTED receipts of the PO.
     *
     * Everything here decides from locked re-reads inside the transaction —
     * the receipts, the earlier claims, the earlier bills — because the race
     * this feature invites is precisely two invoices arriving for one surat
     * jalan. The polite checks give the operator a sentence; the unique index
     * on fin_ap_bill_goods_receipts.goods_receipt_id gives the loser of the
     * race a rollback (claimReceipts()).
     *
     * @param  array<int, int>  $receiptIds
     */
    private function createPartialFromPo(PurchaseOrder $po, array $receiptIds, array $options, string $billDate): ApBill
    {
        if (! $this->receiptClearingAvailable()) {
            throw new LogicException(
                'Modul persediaan tidak tersedia; tagihan atas penerimaan barang tidak dapat dibuat.'
            );
        }

        // Mode exclusivity, this direction: a live whole-PO bill already
        // prices the full order and (once approved) sweeps EVERY receipt of
        // the PO — a partial bill beside it would invoice those goods twice.
        if ($this->wholePoBillExists((int) $po->id)) {
            throw new LogicException(
                "PO {$po->code} sudah memiliki tagihan atas seluruh pesanan; "
                .'penerimaan yang datang setelahnya ditagihkan lewat penerimaan barangnya.'
            );
        }

        if ($this->pendingAdvanceExists((int) $po->id)) {
            throw new LogicException(
                "Uang muka atas {$po->code} masih menunggu persetujuan; setujui atau tolak dulu "
                .'sebelum membuat tagihan parsial.'
            );
        }

        $receipts = DB::table('inv_goods_receipts')
            ->whereIn('id', $receiptIds)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'code', 'purchase_order_id', 'status']);

        foreach ($receiptIds as $receiptId) {
            if (! $receipts->contains(fn (object $receipt): bool => (int) $receipt->id === $receiptId)) {
                throw new LogicException("Penerimaan barang #{$receiptId} tidak ditemukan.");
            }
        }

        foreach ($receipts as $receipt) {
            if ((int) $receipt->purchase_order_id !== (int) $po->id) {
                throw new LogicException("GRN {$receipt->code} bukan penerimaan atas {$po->code}.");
            }

            if ($receipt->status !== StockDocumentStatus::Posted->value) {
                throw new LogicException(
                    "GRN {$receipt->code} berstatus {$receipt->status}; hanya penerimaan yang sudah "
                    .'diposting dapat ditagih.'
                );
            }
        }

        $this->assertReceiptsUnclaimed($receipts);

        [$dpp, $ppn, $slices] = $this->partialBillAmounts($po, $receipts, $options);
        [$pphTaxId, $pphAmount] = $this->resolvePph($options, round(array_sum($slices), 2));

        $codes = $receipts->pluck('code');

        $bill = $this->build([
            'vendor_id' => (int) $po->vendor_id,
            'project_id' => $po->project_id,
            'purchase_order_id' => (int) $po->id,
            'goods_receipt_id' => null,
            'subcontract_claim_id' => null,
            'is_advance' => false,
            'bill_date' => $billDate,
            'due_date' => $options['due_date']
                ?? Carbon::parse($billDate)->addDays((int) $po->payment_term_days)->toDateString(),
            'description' => $options['description'] ?? "Tagihan parsial atas {$po->code} — ".(
                $codes->count() <= 3 ? $codes->implode(', ') : $codes->count().' penerimaan barang'
            ),
            'cost_category' => $options['cost_category'] ?? null,
            'dpp' => $dpp,
            'ppn_amount' => $ppn,
            'pph_tax_id' => $pphTaxId,
            'pph_amount' => $pphAmount,
            'vendor_invoice_no' => $options['vendor_invoice_no'] ?? '',
            'faktur_pajak_no' => $options['faktur_pajak_no'] ?? null,
        ]);

        $this->claimReceipts($bill, $slices);

        return $bill;
    }

    /**
     * Refuse any named receipt that a live bill already covers — through a
     * partial claim row, or through the accrual (goods_receipt_id) route.
     * Rows in fin_ap_bill_goods_receipts belong to live bills only (cancel
     * and delete remove them), so bare existence is the test.
     *
     * @param  Collection<int, object>  $receipts
     */
    private function assertReceiptsUnclaimed(Collection $receipts): void
    {
        $receiptIds = $receipts->pluck('id')->all();

        $claim = DB::table('fin_ap_bill_goods_receipts as claim')
            ->join('fin_ap_bills as bill', 'bill.id', '=', 'claim.ap_bill_id')
            ->whereIn('claim.goods_receipt_id', $receiptIds)
            ->first(['claim.goods_receipt_id', 'bill.code']);

        $claim ??= ApBill::query()
            ->whereIn('goods_receipt_id', $receiptIds)
            ->whereNot('status', DocumentStatus::Cancelled->value)
            ->first(['goods_receipt_id', 'code']);

        if ($claim !== null) {
            $code = $receipts->first(
                fn (object $receipt): bool => (int) $receipt->id === (int) $claim->goods_receipt_id
            )?->code ?? "#{$claim->goods_receipt_id}";

            throw new LogicException("GRN {$code} sudah tercakup pada tagihan {$claim->code}.");
        }
    }

    /**
     * Price the named receipts and net off the header discount and the
     * approved uang muka, both PROGRESSIVELY.
     *
     * Pricing: received qty x the PO line's unit price — the vendor invoices
     * the delivery note at the negotiated price, whatever unit cost the
     * warehouse happened to key on the receipt; the difference lands in
     * 6-4500 at approval exactly as on a whole-PO bill. A receipt line with no
     * PO line behind it (a substituted article, an over-delivery) has no
     * ordered price to hold it to, so it is priced at the value the warehouse
     * actually took it in at — inventing a price would mint variance out of
     * nothing.
     *
     * Progressive share = round(pool x cumulative/base) minus what earlier
     * live bills already took. Each bill takes the difference between the
     * rounded cumulative entitlement and the rupiah already handed out, so
     * when the last slice completes the order the shares sum to the pool
     * EXACTLY — three thirds of Rp 1.000.000 come out 333.333,33 / 333.333,34
     * / 333.333,33, never 999.999,99. Independent rounding per bill drifts by
     * a rupiah and leaves 1-1500 carrying ±0,01 for ever; "why is the
     * prepayment account not zero" is not a conversation worth having over one
     * rupiah. Cancelling a bill deletes its claim rows, so the cumulative
     * arithmetic self-corrects: the next bill re-earns the freed share.
     *
     * The uang muka is recovered proportionally per partial bill — not fully
     * on the first — because the DP secured the WHOLE order: recovering it all
     * against the first delivery can push that bill's payable to zero or
     * below, which reads as "this delivery is free" in the AP aging and
     * starves the vendor of the cash flow the termin pricing promised. The
     * proportional rule keeps every partial bill's payable the same fraction
     * of its delivery, which is how uang muka is netted on termin billing
     * everywhere else in this codebase (AR termin, subcon opname).
     *
     * @param  Collection<int, object>  $receipts
     * @return array{0: float, 1: float, 2: array<int, float>} [net dpp, ppn, receipt id => gross dpp slice]
     */
    private function partialBillAmounts(PurchaseOrder $po, Collection $receipts, array $options): array
    {
        $priced = $this->pricedAtPoRates($receipts->pluck('id')->all());

        if (round(array_sum($priced), 2) <= 0.0) {
            throw new LogicException(
                'Penerimaan yang dipilih tidak memiliki nilai yang dapat ditagih.'
            );
        }

        $subtotal = round((float) $po->subtotal, 2);
        $discount = round((float) $po->discount_amount, 2);

        // What earlier live partial bills of this PO already took, re-read
        // inside the transaction: their claim rows carry the post-discount
        // slices; their priced (pre-discount) value is re-derived from the
        // same immutable inputs (posted receipt lines x approved PO prices).
        $earlierClaims = DB::table('fin_ap_bill_goods_receipts as claim')
            ->join('fin_ap_bills as bill', 'bill.id', '=', 'claim.ap_bill_id')
            ->where('bill.purchase_order_id', $po->id)
            ->get(['claim.goods_receipt_id', 'claim.dpp_amount']);

        $pricedBefore = round(array_sum($this->pricedAtPoRates(
            $earlierClaims->pluck('goods_receipt_id')->map(fn ($id): int => (int) $id)->all()
        )), 2);
        $dppTakenBefore = round((float) $earlierClaims->sum('dpp_amount'), 2);

        // Discount: allocated across the bill's receipts one by one so the
        // claim rows themselves carry post-discount slices that sum to the
        // bill's gross DPP.
        $slices = [];
        $cumPriced = $pricedBefore;
        $discountTaken = $this->progressiveEntitlement($discount, $subtotal, $pricedBefore);

        foreach ($receipts as $receipt) {
            $value = $priced[(int) $receipt->id] ?? 0.0;
            $cumPriced = round($cumPriced + $value, 2);

            $entitled = $this->progressiveEntitlement($discount, $subtotal, $cumPriced);
            $share = min(max(0.0, round($entitled - $discountTaken, 2)), $value);
            $discountTaken = round($discountTaken + $share, 2);

            $slices[(int) $receipt->id] = round($value - $share, 2);
        }

        $grossDpp = round(array_sum($slices), 2);

        // Uang muka: the cumulative base is the post-discount DPP, so a fully
        // billed order (cum == po.dpp) hands back the full advance.
        $advance = $this->approvedAdvanceTotalsFor((int) $po->id)['dpp'];
        $advanceTakenBefore = round($dppTakenBefore - round((float) ApBill::query()
            ->where('purchase_order_id', $po->id)
            ->where('is_advance', false)
            ->whereNot('status', DocumentStatus::Cancelled->value)
            ->whereExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('fin_ap_bill_goods_receipts')
                ->whereColumn('fin_ap_bill_goods_receipts.ap_bill_id', 'fin_ap_bills.id'))
            ->sum('dpp'), 2), 2);

        $recovery = min(
            max(0.0, round($this->progressiveEntitlement(
                $advance,
                round((float) $po->dpp, 2),
                round($dppTakenBefore + $grossDpp, 2),
            ) - $advanceTakenBefore, 2)),
            $grossDpp,
        );

        $dpp = round($grossDpp - $recovery, 2);
        $ppn = isset($options['ppn_amount'])
            ? round((float) $options['ppn_amount'], 2)
            : round($dpp * (float) $po->ppn_rate / 100, 2);

        return [$dpp, $ppn, $slices];
    }

    /**
     * round(pool x min(1, cumulative/base)) — the rounded cumulative
     * entitlement the progressive shares are cut from. Capped at the pool so
     * an over-delivery cannot hand out more discount or advance than exists.
     */
    private function progressiveEntitlement(float $pool, float $base, float $cumulative): float
    {
        if ($pool <= 0.0 || $base <= 0.0) {
            return 0.0;
        }

        return round($pool * min(1.0, $cumulative / $base), 2);
    }

    /**
     * Received value at the PO's own prices, per receipt. Reads only immutable
     * inputs — lines of POSTED receipts and prices of an APPROVED order — so
     * re-deriving it for earlier bills always reproduces what they were priced
     * from.
     *
     * @param  array<int, int>  $receiptIds
     * @return array<int, float> receipt id => value
     */
    private function pricedAtPoRates(array $receiptIds): array
    {
        if ($receiptIds === []) {
            return [];
        }

        $priced = array_fill_keys($receiptIds, 0.0);

        $lines = DB::table('inv_goods_receipt_items as line')
            ->leftJoin('prc_purchase_order_items as po_line', 'po_line.id', '=', 'line.po_item_id')
            ->whereIn('line.goods_receipt_id', $receiptIds)
            ->get(['line.goods_receipt_id', 'line.qty', 'line.amount', 'po_line.unit_price']);

        foreach ($lines as $line) {
            $value = $line->unit_price !== null
                ? round((float) $line->qty * (float) $line->unit_price, 2)
                : round((float) $line->amount, 2);

            $receiptId = (int) $line->goods_receipt_id;
            $priced[$receiptId] = round($priced[$receiptId] + $value, 2);
        }

        return $priced;
    }

    /**
     * Write the claim rows. The polite checks ran a moment ago, but a sibling
     * transaction that claimed the same receipt is invisible to them until it
     * commits — the unique index on goods_receipt_id is what actually decides
     * the race, and its violation rolls this whole bill back.
     *
     * @param  array<int, float>  $slices  receipt id => gross dpp slice
     */
    private function claimReceipts(ApBill $bill, array $slices): void
    {
        try {
            foreach ($slices as $receiptId => $amount) {
                $bill->billedReceipts()->create([
                    'goods_receipt_id' => $receiptId,
                    'dpp_amount' => $amount,
                ]);
            }
        } catch (QueryException $exception) {
            throw new LogicException(
                'Salah satu penerimaan barang baru saja ditagihkan pada tagihan lain; '
                .'muat ulang dan pilih penerimaan yang masih terbuka.',
                previous: $exception,
            );
        }
    }

    /**
     * Bill a POSTED goods receipt whose credit no purchase order will clear.
     *
     * This is the debit path the penerimaan accrual account never had. The
     * receipt credited it; approving this bill debits exactly that recorded
     * amount back out, through the same three-way-match machinery a PO bill
     * uses — so the goods are NOT expensed a second time and the accrual reaches
     * zero without a hand-written journal voucher.
     *
     * Usually such a receipt names no purchase order at all. It may also name
     * one that can no longer produce an invoice — the PO's single final bill is
     * already approved (an over-delivery, or goods that arrived after the
     * invoice), or the order was deleted. StockService credits the accrual in
     * exactly those cases, so refusing them here would strand the very credit
     * it raised; the refusal below is therefore narrowed to the receipts a PO
     * bill can still clear, which must be billed through the PO so the same
     * value is not invoiced twice.
     *
     * A receipt with no counterparty at all recorded no clearing (its credit
     * went to equity as an opening balance and is closed there), so there is
     * nothing here to bill and the attempt is refused.
     */
    public function createFromGoodsReceipt(int $goodsReceiptId, array $options = []): ApBill
    {
        return DB::transaction(function () use ($goodsReceiptId, $options): ApBill {
            if (! empty($options['is_advance'])) {
                throw new LogicException('Uang muka hanya dapat dibuat atas pesanan pembelian (PO).');
            }

            if (! $this->receiptClearingAvailable()) {
                throw new LogicException(
                    'Modul persediaan tidak tersedia; tagihan atas penerimaan barang tidak dapat dibuat.'
                );
            }

            $receipt = DB::table('inv_goods_receipts')
                ->whereNull('deleted_at')
                ->where('id', $goodsReceiptId)
                ->first(['id', 'code', 'vendor_id', 'purchase_order_id', 'status', 'receipt_date', 'gl_clearing_account', 'gl_clearing_amount']);

            if ($receipt === null) {
                throw new LogicException("Penerimaan barang #{$goodsReceiptId} tidak ditemukan.");
            }

            if ($receipt->status !== StockDocumentStatus::Posted->value) {
                throw new LogicException(
                    "GRN {$receipt->code} berstatus {$receipt->status}; hanya penerimaan yang sudah diposting dapat ditagih."
                );
            }

            if ($receipt->purchase_order_id !== null
                && $this->poBillStillPossible((int) $receipt->purchase_order_id, (int) $receipt->id)) {
                throw new LogicException(
                    "GRN {$receipt->code} terkait PO; tagihkan melalui pesanan pembeliannya."
                );
            }

            // Checked before the outstanding amount so the operator is told the
            // useful thing: a second bill is refused because the first one
            // exists, not merely because it left nothing behind.
            if (ApBill::query()
                ->where('goods_receipt_id', $receipt->id)
                ->whereNot('status', DocumentStatus::Cancelled->value)
                ->exists()) {
                throw new LogicException("A bill already exists for GRN {$receipt->code}.");
            }

            $outstanding = $this->receiptOutstanding($receipt, null);

            if ($outstanding <= 0.0) {
                throw new LogicException(
                    "GRN {$receipt->code} tidak memiliki akrual penerimaan yang masih dapat ditagih."
                );
            }

            $vendorId = $options['vendor_id'] ?? $receipt->vendor_id;

            if (empty($vendorId)) {
                throw new LogicException(
                    "GRN {$receipt->code} tidak mencantumkan vendor; pilih vendor untuk tagihan ini."
                );
            }

            $billDate = $options['bill_date'] ?? now()->toDateString();

            return $this->build([
                'vendor_id' => (int) $vendorId,
                'project_id' => $options['project_id'] ?? null,
                'purchase_order_id' => null,
                'goods_receipt_id' => (int) $receipt->id,
                'subcontract_claim_id' => null,
                'is_advance' => false,
                'bill_date' => $billDate,
                'due_date' => $options['due_date']
                    ?? Carbon::parse($billDate)->addDays(30)->toDateString(),
                'description' => $options['description'] ?? "Tagihan penerimaan barang {$receipt->code}",
                'cost_category' => $options['cost_category'] ?? null,
                'dpp' => isset($options['dpp']) ? round((float) $options['dpp'], 2) : $outstanding,
                'ppn_amount' => round((float) ($options['ppn_amount'] ?? 0), 2),
                'pph_tax_id' => null,
                'pph_amount' => 0.0,
                'vendor_invoice_no' => $options['vendor_invoice_no'] ?? '',
                'faktur_pajak_no' => $options['faktur_pajak_no'] ?? null,
            ]);
        });
    }

    /**
     * Bill an approved subcon opname. Copies the claim math: dpp = gross
     * (period work), PPN and PPh final konstruksi as computed on the claim.
     *
     * The retention does NOT change the tax math — PPN and PPh final are charged
     * on the work done, not on what is paid this month — but it does reduce what
     * the subcontractor is paid, and it is credited to 2-1500 Hutang Retensi
     * Subkon when the bill is approved. Until that was wired, the claim withheld
     * retention, RetentionService reported it as held, and this bill paid it out
     * anyway; the two subsystems disagreed and the money went early.
     */
    public function createFromSubconClaim(ProgressClaim $claim, array $options = []): ApBill
    {
        return DB::transaction(function () use ($claim, $options): ApBill {
            if (! empty($options['is_advance'])) {
                throw new LogicException('Uang muka hanya dapat dibuat atas pesanan pembelian (PO).');
            }

            if ($claim->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "Opname {$claim->code} is {$claim->status->value}; only approved claims can be billed."
                );
            }

            // A DP claim (uang muka subkon) pays out from the SPK screen:
            // AdvanceService mints the bill that debits the 1-1500 prepaid
            // asset. THIS path debits subcon COST and writes fin_project_costs
            // — billing the DP here charges the project for work that has not
            // happened, and again when the opnames are billed: temuan #49's
            // double-booking. On a schema without the column the attribute is
            // null and the cast is false, so nothing changes.
            if ((bool) $claim->is_advance) {
                throw new LogicException(
                    "{$claim->code} adalah klaim uang muka; cairkan lewat menu Uang Muka pada SPK-nya, bukan lewat tagihan opname."
                );
            }

            if (ApBill::query()
                ->where('subcontract_claim_id', $claim->id)
                ->whereNot('status', DocumentStatus::Cancelled->value)
                ->exists()) {
                throw new LogicException(
                    "A bill already exists for opname {$claim->code}."
                );
            }

            $subcontract = $claim->subcontract;
            $billDate = $options['bill_date'] ?? now()->toDateString();

            // Resolve the PPh final tax row from the SPK's PP 9/2022 scheme.
            $pphTaxId = null;

            if ($subcontract->pph_scheme !== null) {
                $pphTaxId = Tax::query()
                    ->where('code', Tax::pphFinalCodeForScheme($subcontract->pph_scheme->value))
                    ->value('id');
            }

            return $this->build([
                'vendor_id' => (int) $subcontract->vendor_id,
                'project_id' => $subcontract->project_id,
                'purchase_order_id' => null,
                'goods_receipt_id' => null,
                'subcontract_claim_id' => (int) $claim->id,
                'is_advance' => false,
                'bill_date' => $billDate,
                'due_date' => $options['due_date']
                    ?? Carbon::parse($billDate)->addDays(30)->toDateString(),
                'description' => $options['description']
                    ?? "Tagihan opname {$claim->code} — {$subcontract->title} ({$subcontract->code})",
                'cost_category' => $options['cost_category'] ?? null,
                // NET of the potongan uang muka this opname carries, exactly
                // like a PO final bill priced net of its approved advance:
                // total_payable then equals the claim's net_payable, and
                // approve() adds the recovery back for the cost leg while
                // crediting it out of 1-1500 (see subconAdvanceRecovery).
                'dpp' => round((float) $claim->gross_amount - (float) ($claim->advance_recovery_amount ?? 0), 2),
                'ppn_amount' => round((float) $claim->ppn_amount, 2),
                'pph_tax_id' => $pphTaxId !== null ? (int) $pphTaxId : null,
                'pph_amount' => round((float) $claim->pph_amount, 2),
                'retention_amount' => round((float) $claim->retention_amount, 2),
                'vendor_invoice_no' => $options['vendor_invoice_no'] ?? '',
                'faktur_pajak_no' => $options['faktur_pajak_no'] ?? null,
            ]);
        });
    }

    /**
     * P4 — bill an APPROVED mandor opname (SP3 labor claim), the mirror of
     * createFromSubconClaim with the differences the instrument demands:
     *
     *   - dpp is priced NET of the claim's kasbon deduction, exactly as a
     *     subcon bill is priced net of its potongan uang muka: total_payable
     *     then equals the claim's net_payable, and approve() adds the
     *     deduction back for the labor-cost leg while crediting it out of
     *     1-1370 Piutang Karyawan (laborKasbonDeduction) — the kasbon was
     *     cash advanced earlier, and the wages pay it back;
     *   - the withholding tax row comes from the SP3's LaborPphScheme
     *     (PPH4A2-UMKM, PP 55/2022 0,5%), never from PP 9/2022 — a mandor
     *     borongan is not a certified construction-services provider;
     *   - no retention: upah borongan carries none.
     *
     * Approving the bill is also the moment the kasbon offset becomes a fact:
     * KasbonService::offsetAgainstWageBill records it (and re-refuses a
     * deduction that no longer fits the kasbon's live outstanding), inside
     * the same transaction as the journal.
     */
    public function createFromLaborClaim(LaborClaim $claim, array $options = []): ApBill
    {
        return DB::transaction(function () use ($claim, $options): ApBill {
            if (! empty($options['is_advance'])) {
                throw new LogicException('Uang muka hanya dapat dibuat atas pesanan pembelian (PO).');
            }

            if ($claim->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "Opname mandor {$claim->code} berstatus {$claim->status->value}; hanya opname "
                    .'yang sudah disetujui yang dapat ditagihkan.'
                );
            }

            if (ApBill::query()
                ->where('labor_claim_id', $claim->id)
                ->whereNot('status', DocumentStatus::Cancelled->value)
                ->exists()) {
                throw new LogicException(
                    "Tagihan atas opname mandor {$claim->code} sudah ada."
                );
            }

            $contract = $claim->laborContract;
            $billDate = $options['bill_date'] ?? now()->toDateString();

            // The PPh final tax row for the SP3's scheme (TaxSeeder plants it).
            $pphTaxId = null;
            $taxCode = $contract->pph_scheme?->taxCode();

            if ($taxCode !== null) {
                $pphTaxId = Tax::query()->where('code', $taxCode)->value('id');
            }

            return $this->build([
                'vendor_id' => (int) $contract->vendor_id,
                'project_id' => $contract->project_id,
                'purchase_order_id' => null,
                'goods_receipt_id' => null,
                'subcontract_claim_id' => null,
                'labor_claim_id' => (int) $claim->id,
                'is_advance' => false,
                'bill_date' => $billDate,
                'due_date' => $options['due_date']
                    ?? Carbon::parse($billDate)->addDays(30)->toDateString(),
                'description' => $options['description']
                    ?? "Tagihan opname mandor {$claim->code} — {$contract->title} ({$contract->code})",
                'cost_category' => $options['cost_category'] ?? null,
                // NET of the kasbon deduction — see the docblock above.
                'dpp' => round((float) $claim->gross_amount - (float) $claim->kasbon_deduction_amount, 2),
                'ppn_amount' => round((float) $claim->ppn_amount, 2),
                'pph_tax_id' => $pphTaxId !== null ? (int) $pphTaxId : null,
                'pph_amount' => round((float) $claim->pph_amount, 2),
                'vendor_invoice_no' => $options['vendor_invoice_no'] ?? '',
                'faktur_pajak_no' => $options['faktur_pajak_no'] ?? null,
            ]);
        });
    }

    /**
     * P5 — bill one PPK period billing (prc_work_order_billings).
     *
     * The DPP is the billing's total: a figure DERIVED from the hour-meter
     * register and the calendar by WorkOrderBillingService, never typed. PPN
     * follows the PPK's snapshot rate (0 for a non-PKP lessor, the same
     * master-vendor rule as PO/SPK/SP3). WITHHOLDING IS THE CALLER'S, the
     * documented createFromPo stance: equipment hire with an operator is
     * typically PPh 23 sewa/jasa, but which scheme applies is a statement of
     * fact the operator makes, not a guess this method hard-codes.
     *
     * One billing, at most ONE live bill: the guard below mirrors
     * createFromLaborClaim word for word — a cancelled bill releases its
     * billing (the cancellation reversed the journal), anything else holds it.
     */
    public function createFromWorkOrderBilling(WorkOrderBilling $billing, array $options = []): ApBill
    {
        return DB::transaction(function () use ($billing, $options): ApBill {
            if (! empty($options['is_advance'])) {
                throw new LogicException('Uang muka hanya dapat dibuat atas pesanan pembelian (PO).');
            }

            $workOrder = $billing->workOrder;

            if ($workOrder === null || $workOrder->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "PPK di balik tagihan periode {$billing->code} tidak lagi berstatus disetujui; "
                    .'tagihan AP tidak dapat dibuat darinya.'
                );
            }

            if (ApBill::query()
                ->where('work_order_billing_id', $billing->id)
                ->whereNot('status', DocumentStatus::Cancelled->value)
                ->exists()) {
                throw new LogicException(
                    "Tagihan atas periode PPK {$billing->code} sudah ada."
                );
            }

            $billDate = $options['bill_date'] ?? now()->toDateString();
            $dpp = round((float) $billing->total_amount, 2);
            [$pphTaxId, $pphAmount] = $this->resolvePph($options, $dpp);

            return $this->build([
                'vendor_id' => (int) $workOrder->vendor_id,
                'project_id' => $workOrder->project_id,
                'purchase_order_id' => null,
                'goods_receipt_id' => null,
                'subcontract_claim_id' => null,
                'labor_claim_id' => null,
                'work_order_billing_id' => (int) $billing->id,
                'is_advance' => false,
                'bill_date' => $billDate,
                'due_date' => $options['due_date']
                    ?? Carbon::parse($billDate)->addDays(30)->toDateString(),
                'description' => $options['description']
                    ?? sprintf(
                        'Tagihan periode PPK %s — %s (%s, %s s.d. %s)',
                        $billing->code,
                        $workOrder->title,
                        $workOrder->code,
                        $billing->period_start->toDateString(),
                        $billing->period_end->toDateString(),
                    ),
                'cost_category' => $options['cost_category'] ?? null,
                'dpp' => $dpp,
                'ppn_amount' => round($dpp * (float) $workOrder->ppn_rate / 100, 2),
                'pph_tax_id' => $pphTaxId,
                'pph_amount' => $pphAmount,
                'vendor_invoice_no' => $options['vendor_invoice_no'] ?? '',
                'faktur_pajak_no' => $options['faktur_pajak_no'] ?? null,
            ]);
        });
    }

    /**
     * Approve + auto-journal. The journal shapes are laid out on the class
     * docblock; this method only picks between them from recorded facts:
     *
     *   is_advance                  => prepayment leg, no gate, no project cost;
     *   receipts recorded clearing  => three-way match against those records;
     *   otherwise                   => classic expense/inventory debit.
     *
     * An approved advance for the same PO is netted off in both of the last two
     * shapes: the prepayment is consumed rather than double-counted, and Hutang
     * Usaha is credited only with what is still owed.
     *
     * Project cost is written for whatever this bill charges to a project:
     * the gross DPP on the classic path, and on the matched path the purchase
     * price variance — the only part of a matched bill that is a cost rather
     * than the settlement of a liability.
     */
    public function approve(ApBill $bill, User $by, ?string $note = null): ApBill
    {
        return DB::transaction(function () use ($bill, $by, $note): ApBill {
            // Re-asserted here, not only on the create/update paths, because a
            // draft keyed before those guards existed can still reach approval
            // — and this is the last moment before the withholding lands in a
            // liability account it cannot be moved out of.
            $this->assertPpnCreditable($bill, round((float) $bill->ppn_amount, 2));
            $this->assertWithholdingIdentified($bill, round((float) $bill->pph_amount, 2));

            $bill->approve($by, $note); // Approvable: submitted -> approved

            $category = $this->costCategory($bill);

            /** @var array<string, float> $clearing clearing account code => amount to debit */
            $clearing = [];
            /** @var array<int, float> $perClaimCleared claim row id => amount debited off that receipt */
            $perClaimCleared = [];
            $advanceApplied = 0.0;
            $grossDpp = round((float) $bill->dpp, 2);

            if ($bill->isAdvance()) {
                $this->assertAdvanceStillOpen($bill);

                $valueLines = [[
                    'account_code' => $this->advanceAccountCode(),
                    'debit' => $grossDpp,
                    'description' => $bill->description,
                    'project_id' => $bill->project_id,
                ]];
            } else {
                /** @var Collection<int, ApBillGoodsReceipt> $claims */
                $claims = $bill->billedReceipts()->orderBy('goods_receipt_id')->get();

                if ($claims->isEmpty()) {
                    $this->assertStockCommitmentSettled($bill);
                }

                $this->assertNoPendingAdvance($bill);

                if ($claims->isNotEmpty()) {
                    // Tagihan parsial: clear ONLY the named receipts' slices,
                    // and skip the delivery-completeness gate — billing what
                    // arrived while the remainder is still on the road is the
                    // point, and the remainder stays unbillable because no
                    // receipt exists for a partial bill to name. The uang muka
                    // consumed is the netting recorded at create time (gross
                    // claim slices minus the bill's own net DPP), never the
                    // whole approved advance — that is the proportional rule.
                    [$clearing, $perClaimCleared] = $this->partialClearing($bill, $claims);
                    $advanceApplied = round(max(
                        0.0,
                        round((float) $claims->sum('dpp_amount'), 2) - round((float) $bill->dpp, 2),
                    ), 2);
                } else {
                    $clearing = $this->recordedClearing($bill);
                    $advanceApplied = match (true) {
                        $bill->subcontract_claim_id !== null => $this->subconAdvanceRecovery($bill),
                        // P4: the mandor bill's "advance consumed" is the
                        // kasbon deduction its opname carries — same netting
                        // machinery, different prepaid asset (1-1370).
                        $bill->labor_claim_id !== null => $this->laborKasbonDeduction($bill),
                        default => $this->approvedAdvanceTotals($bill)['dpp'],
                    };
                }

                $grossDpp = round($grossDpp + $advanceApplied, 2);

                $valueLines = $clearing === []
                    ? [[
                        'account_code' => $this->debitAccountCode($bill, $category),
                        'debit' => $grossDpp,
                        'description' => $bill->description,
                        'project_id' => $bill->project_id,
                    ]]
                    : $this->threeWayMatchLines($bill, $clearing, $grossDpp);

                if ($advanceApplied > 0.0) {
                    $isLaborBill = $bill->labor_claim_id !== null;

                    $valueLines[] = [
                        // A labor bill's netting credits the EMPLOYEE advance
                        // (the kasbon's own 1-1370), never 1-1500 Uang Muka
                        // Proyek — the cash left through a petty-cash drawer
                        // to an employee, not through a vendor prepayment.
                        'account_code' => $isLaborBill
                            ? self::EMPLOYEE_ADVANCE_ACCOUNT
                            : $this->advanceAccountCode(),
                        'credit' => $advanceApplied,
                        'description' => $isLaborBill
                            ? "Potongan kasbon {$bill->code}"
                            : "Perhitungan uang muka {$bill->code}",
                        'project_id' => $bill->project_id,
                    ];
                }
            }

            $this->journals->autoPost(
                'ap_bill',
                (int) $bill->id,
                [
                    ...$valueLines,
                    [
                        'account_code' => '1-1600',
                        'debit' => (float) $bill->ppn_amount,
                        'description' => "PPN Masukan {$bill->code}",
                        'project_id' => $bill->project_id,
                    ],
                    [
                        'account_code' => '2-1100',
                        'credit' => (float) $bill->total_payable,
                        'description' => "Hutang usaha {$bill->code}",
                        'project_id' => $bill->project_id,
                    ],
                    [
                        'account_code' => $this->pphLiabilityAccountCode($bill),
                        'credit' => (float) $bill->pph_amount,
                        'description' => "PPh dipotong {$bill->code}",
                        'project_id' => $bill->project_id,
                    ],
                    [
                        'account_code' => self::SUBCON_RETENTION_ACCOUNT,
                        'credit' => (float) ($bill->retention_amount ?? 0),
                        'description' => "Retensi subkon ditahan {$bill->code}",
                        'project_id' => $bill->project_id,
                    ],
                ],
                $bill->bill_date->toDateString(),
                "Bill {$bill->code} — {$bill->description}",
                (int) $by->id,
            );

            // What this bill actually took out of the clearing accounts and out
            // of the prepayment, recorded from what was posted. The outstanding
            // calculation for any later bill subtracts these, so the same credit
            // can never be cleared twice.
            //
            // The bukti potong number is minted here for the same reason: this
            // is the moment the withholding becomes a fact and the bill stops
            // being editable, so it is the moment the certificate can be issued.
            $bill->forceFill([
                'gl_cleared_amount' => round(array_sum($clearing), 2),
                'advance_applied_amount' => $advanceApplied,
                'bupot_no' => $this->buktiPotongNumberFor($bill),
            ])->save();

            // P4: the moment the 1-1370 credit is posted is the moment the
            // kasbon offset becomes a fact — recorded through KasbonService
            // (the documented Finance seam), inside this same transaction.
            // offsetAgainstWageBill re-refuses a deduction the kasbon's LIVE
            // outstanding no longer covers (another wage bill approved in
            // between), rolling this approval back journal and all.
            if ($bill->labor_claim_id !== null && $advanceApplied > 0.0) {
                $this->kasbons()->offsetAgainstWageBill(
                    $this->laborClaimKasbonOrFail($bill),
                    $advanceApplied,
                    $bill->bill_date->toDateString(),
                );
            }

            // The same record, per receipt, for a partial bill: which slice of
            // each named receipt's clearing this approval consumed. Written on
            // the claim rows because the outstanding of ONE receipt must never
            // be answered by pooling a bill total across the PO oldest-first —
            // that pooling is exactly right for a whole-PO bill and exactly
            // wrong for a partial one.
            foreach ($perClaimCleared as $claimId => $amount) {
                ApBillGoodsReceipt::query()->whereKey($claimId)->update(['cleared_amount' => $amount]);
            }

            // What this bill charges to the project, mirroring the GL exactly:
            //
            //   classic    the whole gross DPP is the cost (PPN Masukan is
            //              recoverable, so it is excluded);
            //   matched    the goods themselves are NOT a cost here — they are
            //              costed when they are issued — but the price variance
            //              is, and it is posted to the GL carrying this bill's
            //              project. Leaving it out of fin_project_costs is what
            //              made the project P&L disagree with the ledger by the
            //              variance;
            //   advance    an asset, never a cost.
            //
            // Both branches record under the same (reference, category) key, and
            // they are mutually exclusive, so ProjectCostService's idempotency
            // still holds.
            if ($bill->project_id !== null && ! $bill->isAdvance()) {
                if ($clearing === []) {
                    $this->projectCosts->record(
                        (int) $bill->project_id,
                        $bill->bill_date->toDateString(),
                        $category,
                        'ap_bill',
                        (int) $bill->id,
                        $bill->description,
                        $grossDpp,
                    );
                } else {
                    $this->recordVarianceProjectCost($bill, $category, $this->purchaseVariance($clearing, $grossDpp));
                }
            }

            return $bill->refresh();
        });
    }

    /**
     * Membatalkan tagihan vendor yang terlanjur disetujui (dan berjurnal).
     *
     * A wrong approved bill used to be permanent: the payable sat in the AP
     * aging forever, its PO stayed spent — finalBillExists() refuses a second
     * final bill, and an advance is frozen once a final exists — and the project
     * P&L kept a cost the vendor never charged. A manual JV fixed the GL and
     * left every one of those subledger facts wrong.
     *
     * Conditions: nothing has been paid, a reason, and the BILL'S OWN fiscal
     * period still open (the reversal carries the bill's date; see the
     * cancellation migration for why).
     *
     * Releasing the derived state is most of the work. Status alone frees what
     * is derived by query — the PO's final-bill slot, an advance's frozen state,
     * the subcon opname, the GR/IR sweep — since all of those already exclude
     * cancelled bills. What status cannot reach is written down, so it is
     * cleared here: the recorded clearing/advance amounts, which the reversal
     * has just given back, and the project cost row, which no longer has a
     * ledger entry behind it.
     */
    public function cancel(ApBill $bill, User $by, ?string $reason = null): ApBill
    {
        return DB::transaction(function () use ($bill, $by, $reason): ApBill {
            /** @var ApBill $bill */
            $bill = ApBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            $reason = trim((string) $reason);

            if ($reason === '') {
                throw new LogicException('Alasan pembatalan wajib diisi.');
            }

            if ($bill->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "Tagihan {$bill->code} berstatus {$bill->status->value}; hanya tagihan yang sudah disetujui yang dapat dibatalkan."
                );
            }

            // Lihat catatan yang sama di ArInvoiceService::cancel(): pembayaran
            // terposting tidak punya jalur pembatalan, jadi jangan menyuruh
            // operator melakukannya.
            if ((float) $bill->amount_paid > 0) {
                throw new LogicException(
                    "Tagihan {$bill->code} sudah dibayar {$bill->amount_paid}; "
                    .'hanya tagihan yang belum dibayar yang dapat dibatalkan — pembayaran yang terlanjur salah dikoreksi lewat jurnal.'
                );
            }

            $this->assertAdvanceNotConsumed($bill);
            $this->assertRetentionNotReleased($bill);

            // P4: the reversal below re-debits 1-1370 for the kasbon deduction
            // this bill posted, so the offset recorded on the kasbon has to be
            // handed back too — through the same KasbonService seam, which
            // REFUSES (and thereby aborts this cancellation) when the kasbon
            // can no longer honestly take it back: already swept into a
            // replenishment payment, or since settled with receipts.
            if ($bill->labor_claim_id !== null && (float) $bill->advance_applied_amount > 0.0) {
                $this->kasbons()->releaseWageOffset(
                    $this->laborClaimKasbonOrFail($bill),
                    round((float) $bill->advance_applied_amount, 2),
                );
            }

            $this->journals->reverseFor(
                'ap_bill',
                (int) $bill->id,
                'ap_bill_cancellation',
                "Pembatalan tagihan {$bill->code} — {$reason}",
                (int) $by->id,
                $this->journals->reversalDate($bill->bill_date),
            );

            // Recorded facts about a posting that no longer stands. The reversal
            // credited the clearing account back and re-debited the prepayment,
            // so leaving these numbers would understate what the next bill may
            // still sweep by exactly the amount this one gave back.
            $bill->forceFill([
                'gl_cleared_amount' => 0,
                'advance_applied_amount' => 0,
                'status' => DocumentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancellation_reason' => $reason,
            ])->save();

            // Releasing the named receipts is the partial-bill mirror of the
            // zeroed clearing above: the reversal has just credited each slice
            // back, and the claim rows are what refuse a replacement bill —
            // leaving them would lock every one of those deliveries out of
            // re-billing for ever (the unique index holds only live claims).
            $bill->billedReceipts()->delete();

            $this->projectCosts->remove('ap_bill', (int) $bill->id);

            $bill->approvals()->create([
                'action' => 'cancelled',
                'user_id' => $by->id,
                'note' => $reason,
            ]);

            return $bill->refresh();
        });
    }

    /**
     * The editable check runs INSIDE the transaction, on a locked re-read, for
     * the reason JournalService::update() sets out: a route-bound model is
     * read several DB round-trips before the handler reaches this line, and an
     * approval landing inside that window is invisible to the copy in hand.
     * On a bill the stale edit is money-wrong twice over — approve() has
     * already posted the three-way-match or advance journal off dpp, ppn and
     * pph, and stamped the bupot number on the withholding — so a later fill()
     * would leave the GL, the AP aging and the bukti potong disagreeing about
     * what the vendor is owed.
     *
     * lockForUpdate() is a no-op on SQLite; the re-read plus the re-check on
     * the re-read instance is the real protection.
     */
    public function update(ApBill $bill, array $data): ApBill
    {
        return DB::transaction(function () use ($bill, $data): ApBill {
            /** @var ApBill $bill */
            $bill = ApBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($bill);

            // A partial bill's DPP is DERIVED — received qty x PO price, less
            // the progressive discount and uang muka shares — and its claim
            // rows carry the same arithmetic. A hand edit would desync the
            // three (approve() reads the netting as claims minus dpp), so the
            // repair for a wrong partial bill is cancel-and-reissue, never a
            // typed-over number.
            if (array_key_exists('dpp', $data)
                && round((float) $data['dpp'], 2) !== round((float) $bill->dpp, 2)
                && $bill->isPartial()) {
                throw new LogicException(
                    "DPP tagihan parsial {$bill->code} diturunkan dari penerimaan yang ditagihnya "
                    .'dan tidak dapat diubah; batalkan tagihannya dan terbitkan ulang.'
                );
            }

            // P5 — the same stance for a PPK period bill: its DPP is the
            // billing total WorkOrderBillingService derived from the
            // hour-meter register and the calendar. A typed-over number would
            // break the one-period-one-amount chain the billing guards, so
            // the repair is cancel-and-reissue here too.
            if (array_key_exists('dpp', $data)
                && round((float) $data['dpp'], 2) !== round((float) $bill->dpp, 2)
                && $bill->work_order_billing_id !== null) {
                throw new LogicException(
                    "DPP tagihan {$bill->code} diturunkan dari tagihan periode PPK "
                    .'(register hour-meter/kalender) dan tidak dapat diubah; batalkan tagihannya '
                    .'dan terbitkan ulang.'
                );
            }

            $bill->fill(Arr::only($data, [
                'bill_date', 'due_date', 'description', 'cost_category', 'dpp', 'ppn_amount',
                'pph_tax_id', 'pph_amount', 'vendor_invoice_no', 'faktur_pajak_no',
            ]));

            // The same derive-from-rate convenience the create paths offer, so
            // the form's own hint — "Kosongkan untuk menghitung dari tarif pajak
            // yang dipilih" — is true on the edit screen too. It was not: a
            // draft repaired by picking "PPh 23 Jasa" and leaving the amount
            // blank kept pph_amount 0,00 and a total_payable that still paid the
            // withholding to the vendor, while naming the tax it had not
            // withheld.
            //
            // Only when THIS edit names the tax. An edit that never mentions
            // PPh must not re-derive an amount the operator settled earlier.
            if (($data['pph_tax_id'] ?? null) !== null) {
                [$bill->pph_tax_id, $bill->pph_amount] = $this->resolvePph([
                    'pph_tax_id' => $data['pph_tax_id'],
                    'pph_amount' => $data['pph_amount'] ?? $bill->pph_amount,
                ], round((float) $bill->dpp, 2));
            }

            $this->recalc($bill);
            $bill->save();

            return $bill->refresh();
        });
    }

    /**
     * Same window as update(), landing on soft-delete: a bill approved between
     * the route binding and this call would vanish from the AP aging and from
     * DanglingDocuments while its posted journal kept the liability in 2-1100
     * and the GR/IR clearing it consumed stayed consumed.
     */
    public function delete(ApBill $bill): void
    {
        DB::transaction(function () use ($bill): void {
            /** @var ApBill $bill */
            $bill = ApBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($bill);

            // A draft claimed its receipts the moment it was created; deleting
            // the draft must hand them back or nothing ever can.
            $bill->billedReceipts()->delete();

            $bill->delete();
        });
    }

    private function createManual(array $data): ApBill
    {
        return DB::transaction(function () use ($data): ApBill {
            if (! empty($data['is_advance'])) {
                throw new LogicException('Uang muka hanya dapat dibuat atas pesanan pembelian (PO).');
            }

            $billDate = $data['bill_date'] ?? now()->toDateString();
            [$pphTaxId, $pphAmount] = $this->resolvePph($data, round((float) $data['dpp'], 2));

            return $this->build([
                'vendor_id' => (int) $data['vendor_id'],
                'project_id' => $data['project_id'] ?? null,
                'purchase_order_id' => null,
                'goods_receipt_id' => null,
                'subcontract_claim_id' => null,
                'is_advance' => false,
                'bill_date' => $billDate,
                'due_date' => $data['due_date']
                    ?? Carbon::parse($billDate)->addDays(30)->toDateString(),
                'description' => $data['description'],
                'cost_category' => $data['cost_category'] ?? null,
                'dpp' => round((float) $data['dpp'], 2),
                'ppn_amount' => round((float) ($data['ppn_amount'] ?? 0), 2),
                'pph_tax_id' => $pphTaxId,
                'pph_amount' => $pphAmount,
                'vendor_invoice_no' => $data['vendor_invoice_no'] ?? '',
                'faktur_pajak_no' => $data['faktur_pajak_no'] ?? null,
            ]);
        });
    }

    private function build(array $attributes): ApBill
    {
        $bill = new ApBill($attributes);
        $bill->status = DocumentStatus::Draft;
        $bill->amount_paid = 0;
        $bill->gl_cleared_amount = 0;
        $bill->advance_applied_amount = 0;

        $this->recalc($bill);
        $bill->save(); // HasDocumentNumber fills the BIL code

        return $bill;
    }

    /**
     * The withheld PPh a create or update path should store.
     *
     * ONE rule, in one place, because it used to exist only in createManual():
     * createFromPo() discarded the pair outright and update() stored whatever
     * arrived, so the same two form fields meant three different things
     * depending on which button was pressed.
     *
     * Naming the tax and leaving the amount blank derives it from the rate —
     * PPh 23 Jasa 2 % of Rp 115.600.000 = Rp 2.312.000. An amount that is
     * stated is never overwritten: the tax office's letter, not our master
     * rate, is what was actually withheld.
     *
     * @param  array<string, mixed>  $data
     * @param  float  $base  the jumlah bruto the rate applies to
     * @return array{0: ?int, 1: float} [pph_tax_id, pph_amount]
     */
    private function resolvePph(array $data, float $base): array
    {
        $taxId = ($data['pph_tax_id'] ?? null) !== null ? (int) $data['pph_tax_id'] : null;
        $amount = round((float) ($data['pph_amount'] ?? 0), 2);

        if ($taxId !== null && $amount === 0.0) {
            $tax = Tax::query()->find($taxId);
            $amount = $tax !== null ? $tax->amountOn($base) : 0.0;
        }

        return [$taxId, $amount];
    }

    private function recalc(ApBill $bill): void
    {
        $dpp = round((float) $bill->dpp, 2);
        $ppn = round((float) $bill->ppn_amount, 2);
        $pph = round((float) $bill->pph_amount, 2);
        $retention = round((float) ($bill->retention_amount ?? 0), 2);

        if ($pph > $dpp) {
            throw new LogicException('PPh withheld cannot exceed the bill DPP.');
        }

        if ($retention > $dpp) {
            throw new LogicException('Retention withheld cannot exceed the bill DPP.');
        }

        $this->assertPpnCreditable($bill, $ppn);
        $this->assertWithholdingIdentified($bill, $pph);

        // Retention is money not paid this month; it stays a liability to the
        // subcontractor until released, so it comes off the payable and is
        // credited to 2-1500 when the bill is approved.
        $bill->total_payable = round($dpp + $ppn - $pph - $retention, 2);
    }

    /**
     * A vendor that is not PKP cannot issue a faktur pajak, so there is no
     * input VAT to credit and no PPN to pay it.
     *
     * approve() debits 1-1600 PPN Masukan unconditionally, so a clerk filling
     * PPN Rp 11.000.000 out of habit on a Rp 100.000.000 bill from CV Karya
     * Sipil Sejahtera (prc_vendors.is_pkp = 0, sppkp_number NULL) booked
     * Rp 11.000.000 of a receivable from DJP that can never be recovered — and
     * raised total_payable to Rp 111.000.000, so the company actually PAYS the
     * vendor Rp 11.000.000 he may not collect. Downstream it also understates
     * the projected PPN remittance one-for-one, because
     * CashFlowService::projectTaxes() nets the 1-1600 debit balance off the
     * output VAT.
     *
     * The rule is not new; only this path was missing it. PoService::recalcTotals
     * (`$ppnRate = $vendor?->is_pkp ? ... : 0.0`) and SubcontractService::create
     * ("Non-PKP subcontractors cannot issue a faktur pajak: no PPN") have
     * enforced it all along, and docs/ARCHITECTURE.md states it as "PO (PPN only
     * for PKP vendors)".
     *
     * A vendor that cannot be resolved at all is not judged: master data missing
     * is a different problem from master data saying no.
     */
    private function assertPpnCreditable(ApBill $bill, float $ppn): void
    {
        if ($ppn <= 0.0) {
            return;
        }

        $vendor = $bill->vendor()->first();

        if ($vendor === null || (bool) $vendor->is_pkp) {
            return;
        }

        throw new LogicException(
            "Vendor {$vendor->name} bukan PKP sehingga tidak dapat menerbitkan faktur pajak; "
            .'tagihan ini tidak boleh memungut PPN.'
        );
    }

    /**
     * A withholding has to say WHICH withholding it is.
     *
     * pph_tax_id and pph_amount were independently nullable, and
     * pphLiabilityAccountCode() closed with a bare `return '2-1220'`. So a
     * subcon opname keyed manually with Rp 25.837.500 of PPh final Pasal 4(2)
     * and the "Jenis PPh" lookup left blank credited Hutang PPh 23 instead of
     * 2-1230 Hutang PPh Final 4(2): the 10th-of-the-month SSP for PPh 23 came
     * out Rp 25.837.500 too high and the one for PPh final that much too low,
     * while 2-1230 — which SettleableLiabilities exists to let the company remit
     * — carried nothing. The bill could not be repaired either, because the
     * e-Bupot blocker ("pilih jenis pajaknya pada tagihan") names an action
     * assertEditable() refuses on an approved bill.
     *
     * A soft-deleted tax row is refused for the same reason: Rule::exists on
     * fin_taxes does not exclude one, and a bill created against it would post
     * down whatever fallback survived it.
     */
    private function assertWithholdingIdentified(ApBill $bill, float $pph): void
    {
        if ($pph <= 0.0) {
            return;
        }

        if ($bill->pph_tax_id === null) {
            throw new LogicException(
                'Tagihan yang memotong PPh harus menyebut jenis PPh-nya; pilih "Jenis PPh dipotong" '
                .'agar potongannya masuk ke akun hutang pajak yang benar.'
            );
        }

        /** @var ?Tax $tax */
        $tax = Tax::withTrashed()->whereKey($bill->pph_tax_id)->first();

        if ($tax === null || $tax->trashed()) {
            throw new LogicException(
                'Jenis PPh yang dipilih sudah dihapus dari master pajak; pilih jenis PPh yang masih aktif.'
            );
        }

        if ($tax->tax_type !== TaxType::PphWithholding) {
            throw new LogicException(
                "Pajak {$tax->code} bukan jenis PPh dipotong; pilih jenis PPh yang benar."
            );
        }
    }

    // ------------------------------------------------------------ advances (uang muka)

    /**
     * Down-payment amounts. The caller states the DPP (there is no rule that
     * fixes it — 20 %, 30 % and "one truckload" are all normal); PPN follows the
     * PO's own rate, which is already 0 for a non-PKP vendor.
     *
     * @return array{0: float, 1: float} [dpp, ppn]
     */
    private function advanceAmounts(PurchaseOrder $po, array $options): array
    {
        if (ApBill::query()
            ->where('purchase_order_id', $po->id)
            ->where('is_advance', true)
            ->whereNot('status', DocumentStatus::Cancelled->value)
            ->exists()) {
            throw new LogicException("An advance bill already exists for PO {$po->code}.");
        }

        // The advance has to come first: once the final invoice exists there is
        // nothing left for a prepayment to be netted against — and the partial
        // bills' progressive netting was computed from the advances approved
        // when each was priced, so a DP arriving mid-stream would never be
        // recovered by the slices already issued.
        if ($this->partialBillsExist((int) $po->id)) {
            throw new LogicException(
                "PO {$po->code} sudah ditagih parsial; uang muka harus dibuat sebelum "
                .'tagihan pertama atas PO itu.'
            );
        }

        if ($this->finalBillExists((int) $po->id)) {
            throw new LogicException(
                "PO {$po->code} sudah memiliki tagihan final; uang muka tidak dapat dibuat lagi."
            );
        }

        $dpp = round((float) ($options['dpp'] ?? 0), 2);

        if ($dpp <= 0.0) {
            throw new LogicException("Uang muka atas {$po->code} memerlukan nilai DPP.");
        }

        if ($dpp > round((float) $po->dpp, 2) + 0.005) {
            throw new LogicException(
                "Uang muka atas {$po->code} tidak boleh melebihi nilai PO ({$po->dpp})."
            );
        }

        $ppn = isset($options['ppn_amount'])
            ? round((float) $options['ppn_amount'], 2)
            : round($dpp * (float) $po->ppn_rate / 100, 2);

        return [$dpp, $ppn];
    }

    /**
     * Final-bill amounts: the PO terms less any APPROVED advance, so
     * total_payable is the pelunasan the vendor is still owed and the netting
     * posted at approval balances against it.
     *
     * @return array{0: float, 1: float} [dpp, ppn]
     */
    private function finalBillAmounts(PurchaseOrder $po): array
    {
        // Mode exclusivity, the other direction from createPartialFromPo():
        // a whole-PO bill prices the full order and sweeps every receipt,
        // including the deliveries the partial bills already invoiced.
        if ($this->partialBillsExist((int) $po->id)) {
            throw new LogicException(
                "PO {$po->code} sudah ditagih parsial; pilih penerimaan barang yang akan "
                .'ditagih pada tagihan berikutnya.'
            );
        }

        if ($this->finalBillExists((int) $po->id)) {
            throw new LogicException("A bill already exists for PO {$po->code}.");
        }

        if ($this->pendingAdvanceExists((int) $po->id)) {
            throw new LogicException(
                "Uang muka atas {$po->code} masih menunggu persetujuan; setujui atau tolak dulu "
                .'sebelum membuat tagihan final.'
            );
        }

        $advances = $this->approvedAdvanceTotalsFor((int) $po->id);

        return [
            round((float) $po->dpp - $advances['dpp'], 2),
            round((float) $po->ppn_amount - $advances['ppn'], 2),
        ];
    }

    /**
     * Is a vendor invoice for this purchase order still going to debit the
     * credit THIS receipt carries — either one that exists and has not been
     * approved yet, or one that can still be created?
     *
     * This is what keeps the two clearing routes disjoint. A receipt may be
     * billed on its own only when the PO route is provably finished with it:
     *
     *   a partial bill claims this receipt             => TRUE, whatever its
     *       state: a draft/submitted claim will sweep the slice when approved,
     *       an approved one already has. Either way the PO route owns it;
     *   a WHOLE-PO bill exists but is not approved     => TRUE. Approving it
     *       runs recordedClearing() once and sweeps every credit its PO's
     *       receipts recorded, this one included. Billing the receipt
     *       separately would clear the same credit twice;
     *   a whole-PO bill is already approved            => FALSE. Its one sweep
     *       is spent and finalBillExists() forbids a replacement, so the credit
     *       has no other way out — the documented home of the over-delivery
     *       and the goods that arrived after the invoice;
     *   otherwise                                      => createFromPo()'s own
     *       gates decide: the order must resolve (a soft-deleted one is
     *       invisible to findOrFail) and be approved or closed — a fresh bill,
     *       partial or whole, can then still cover this receipt.
     *
     * The mirror image of StockService::purchaseOrderCanStillClear(), which
     * decides which liability a NEW receipt raises. Guarded: without
     * Procurement there is no PO and therefore no PO bill.
     */
    private function poBillStillPossible(int $purchaseOrderId, int $goodsReceiptId): bool
    {
        if (DB::table('fin_ap_bill_goods_receipts')
            ->where('goods_receipt_id', $goodsReceiptId)
            ->exists()) {
            return true;
        }

        if (ApBill::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('is_advance', false)
            ->whereIn('status', [DocumentStatus::Draft->value, DocumentStatus::Submitted->value])
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('fin_ap_bill_goods_receipts')
                ->whereColumn('fin_ap_bill_goods_receipts.ap_bill_id', 'fin_ap_bills.id'))
            ->exists()) {
            return true;
        }

        if (! class_exists(PurchaseOrder::class) || ! Schema::hasTable('prc_purchase_orders')) {
            return false;
        }

        /** @var ?PurchaseOrder $po */
        $po = PurchaseOrder::query()->find($purchaseOrderId);

        if ($po === null) {
            return false;
        }

        if ($po->status !== DocumentStatus::Approved && $po->status !== DocumentStatus::Closed) {
            return false;
        }

        return ! $this->wholePoBillExists((int) $po->id);
    }

    /**
     * A live non-advance bill covering the WHOLE order — one with no claim
     * rows. The two billing modes are mutually exclusive per PO, and this is
     * the test for the whole-PO side of that fence.
     */
    private function wholePoBillExists(int $purchaseOrderId): bool
    {
        return ApBill::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('is_advance', false)
            ->whereNot('status', DocumentStatus::Cancelled->value)
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('fin_ap_bill_goods_receipts')
                ->whereColumn('fin_ap_bill_goods_receipts.ap_bill_id', 'fin_ap_bills.id'))
            ->exists();
    }

    /**
     * Any live claim row against this PO's bills. Claim rows are deleted with
     * their bill's cancellation, so bare existence means a live partial bill.
     */
    private function partialBillsExist(int $purchaseOrderId): bool
    {
        return DB::table('fin_ap_bill_goods_receipts')
            ->join('fin_ap_bills', 'fin_ap_bills.id', '=', 'fin_ap_bill_goods_receipts.ap_bill_id')
            ->where('fin_ap_bills.purchase_order_id', $purchaseOrderId)
            ->exists();
    }

    private function finalBillExists(int $purchaseOrderId): bool
    {
        return ApBill::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('is_advance', false)
            ->whereNot('status', DocumentStatus::Cancelled->value)
            ->exists();
    }

    private function pendingAdvanceExists(int $purchaseOrderId): bool
    {
        return ApBill::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('is_advance', true)
            ->whereIn('status', [DocumentStatus::Draft->value, DocumentStatus::Submitted->value])
            ->exists();
    }

    /**
     * @return array{dpp: float, ppn: float}
     */
    private function approvedAdvanceTotals(ApBill $bill): array
    {
        if ($bill->purchase_order_id === null) {
            return ['dpp' => 0.0, 'ppn' => 0.0];
        }

        return $this->approvedAdvanceTotalsFor((int) $bill->purchase_order_id);
    }

    /**
     * Only APPROVED advances count: an advance that was never approved never
     * debited the prepayment account, so there is nothing to consume.
     *
     * @return array{dpp: float, ppn: float}
     */
    private function approvedAdvanceTotalsFor(int $purchaseOrderId): array
    {
        $advances = ApBill::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('is_advance', true)
            ->where('status', DocumentStatus::Approved->value)
            ->selectRaw('COALESCE(SUM(dpp), 0) as dpp, COALESCE(SUM(ppn_amount), 0) as ppn')
            ->first();

        return [
            'dpp' => round((float) ($advances->dpp ?? 0), 2),
            'ppn' => round((float) ($advances->ppn ?? 0), 2),
        ];
    }

    /**
     * An advance may not be approved once a final bill for the PO exists in any
     * live state: that final was priced net of the advances approved at the time
     * it was raised, so a late prepayment would debit 1-1500 with nothing left to
     * credit it back.
     */
    private function assertAdvanceStillOpen(ApBill $bill): void
    {
        if ($bill->purchase_order_id === null) {
            throw new LogicException('Uang muka hanya dapat dibuat atas pesanan pembelian (PO).');
        }

        if ($this->finalBillExists((int) $bill->purchase_order_id)) {
            throw new LogicException(
                "Tagihan final atas PO ini sudah dibuat; uang muka {$bill->code} tidak dapat disetujui lagi."
            );
        }
    }

    /**
     * The mirror of assertAdvanceStillOpen(), for the other direction: an
     * advance cannot be cancelled while ANY live final bill for the same PO
     * exists — approved, submitted or still a draft.
     *
     * The approved case is the obvious one: reversing the advance would credit
     * 1-1500 a second time (once for the consumption, once for the
     * cancellation) and drive the prepayment asset negative against a PO that
     * owes nothing.
     *
     * The DRAFT case is the one that cost money, and the old test —
     * `advance_applied_amount > 0`, a column build() sets to 0 and only
     * approve() ever raises — could not see it. finalBillAmounts() prices a
     * final bill NET of the approved advance at CREATE time, so on a
     * Rp 111.000.000 rental PO with a 30 % uang muka the draft final reads
     * Rp 77.700.000. Cancelling the unpaid advance while that draft sits there
     * left the draft net of an advance that no longer exists: approving it
     * credited Hutang Usaha Rp 77.700.000 for work worth Rp 111.000.000,
     * understated the project's rental cost by Rp 30.000.000 and input VAT by
     * Rp 3.300.000, and finalBillExists() then refused both a second bill and a
     * replacement advance. The journal balanced and nothing warned.
     *
     * Order of operations for the operator: withdraw the final bill first, then
     * the advance, then re-raise the final — finalBillAmounts() prices it at the
     * full gross once no approved advance remains.
     */
    private function assertAdvanceNotConsumed(ApBill $bill): void
    {
        if (! $bill->isAdvance()) {
            return;
        }

        // Subcon DP payout: cancelling it while approved opname bills have
        // already netted the DP off would reverse a 1-1500 debit whose credits
        // stand — the prepaid asset goes negative and the subcontractor keeps
        // deductions for money the ledger now says was never paid. The opname
        // bills go first; cancelling them gives the credits back (the same
        // one-way order the PO advance enforces below against its final bill).
        if ($bill->purchase_order_id === null) {
            if ($bill->subcontract_claim_id !== null && Schema::hasTable('scm_progress_claims')) {
                $subcontractId = DB::table('scm_progress_claims')
                    ->where('id', $bill->subcontract_claim_id)
                    ->value('subcontract_id');

                $consumed = $subcontractId === null ? 0.0 : round((float) ApBill::query()
                    ->join('scm_progress_claims as c', 'c.id', '=', 'fin_ap_bills.subcontract_claim_id')
                    ->where('c.subcontract_id', $subcontractId)
                    ->where('fin_ap_bills.is_advance', false)
                    ->where('fin_ap_bills.status', DocumentStatus::Approved->value)
                    ->sum('fin_ap_bills.advance_applied_amount'), 2);

                if ($consumed > 0.0) {
                    throw new LogicException(
                        "Uang muka {$bill->code} sudah diperhitungkan {$consumed} pada tagihan opname yang disetujui; "
                        .'batalkan dulu tagihan opnamenya sebelum membatalkan pencairan uang muka.'
                    );
                }
            }

            return;
        }

        $consumer = ApBill::query()
            ->where('purchase_order_id', $bill->purchase_order_id)
            ->where('is_advance', false)
            ->whereNot('status', DocumentStatus::Cancelled->value)
            ->value('code');

        if ($consumer !== null) {
            throw new LogicException(
                "Tagihan {$consumer} atas PO yang sama sudah dinilai bersih dari uang muka {$bill->code}; "
                .'batalkan atau hapus tagihan itu lebih dulu, lalu terbitkan ulang pada nilai penuh.'
            );
        }
    }

    /**
     * A subcon bill may not be cancelled once the retention it withheld has been
     * released.
     *
     * Its `Cr 2-1500` is the only credit standing behind the release's
     * `Dr 2-1500`. Reversing it leaves the retention account carrying a DEBIT
     * balance — the ledger claiming the subcontractor owes US retention — and an
     * approved payable to that subcontractor with no liability behind it. The
     * release bill goes first; it is the document that spent the credit, and
     * cancelling it puts the balance straight back (RetentionService::balance()
     * stops counting a release whose bill was cancelled).
     *
     * The comparison is against what would REMAIN booked after this
     * cancellation, not against zero, so an SPK with several opnames can still
     * withdraw the one that has not been released.
     *
     * Read through the schema rather than the Subcontract models, so Finance
     * still cancels bills on an installation without that module.
     */
    private function assertRetentionNotReleased(ApBill $bill): void
    {
        $retention = round((float) ($bill->retention_amount ?? 0), 2);

        if ($bill->subcontract_claim_id === null || $retention <= 0.0) {
            return;
        }

        if (! Schema::hasTable('scm_retention_releases')
            || ! Schema::hasColumn('scm_retention_releases', 'ap_bill_id')
            || ! Schema::hasTable('scm_progress_claims')) {
            return;
        }

        $subcontractId = DB::table('scm_progress_claims')
            ->where('id', $bill->subcontract_claim_id)
            ->value('subcontract_id');

        if ($subcontractId === null) {
            return;
        }

        // Retention this SPK still has credited to 2-1500 once this bill is gone.
        $remaining = round((float) DB::table('fin_ap_bills as b')
            ->join('scm_progress_claims as c', 'c.id', '=', 'b.subcontract_claim_id')
            ->where('c.subcontract_id', $subcontractId)
            ->where('b.status', DocumentStatus::Approved->value)
            ->whereNull('b.deleted_at')
            ->where('b.id', '!=', $bill->getKey())
            ->sum('b.retention_amount'), 2);

        // Releases still standing: one whose own bill was cancelled has already
        // given its debit back, so it holds nothing.
        $released = round((float) DB::table('scm_retention_releases as r')
            ->leftJoin('fin_ap_bills as rb', 'rb.id', '=', 'r.ap_bill_id')
            ->where('r.subcontract_id', $subcontractId)
            ->where(fn ($query) => $query
                ->whereNull('r.ap_bill_id')
                ->orWhereNot('rb.status', DocumentStatus::Cancelled->value))
            ->sum('r.amount'), 2);

        if ($released > $remaining + 0.01) {
            throw new LogicException(
                "Retensi SPK ini sudah dilepas sebesar {$released} dan hanya {$remaining} yang masih "
                ."terbukukan tanpa {$bill->code}; batalkan dulu tagihan pelepasan retensinya."
            );
        }
    }

    private function assertNoPendingAdvance(ApBill $bill): void
    {
        if ($bill->purchase_order_id === null) {
            return;
        }

        if ($this->pendingAdvanceExists((int) $bill->purchase_order_id)) {
            throw new LogicException(
                "Tagihan {$bill->code} tidak dapat disetujui selama uang muka atas PO yang sama "
                .'masih menunggu persetujuan.'
            );
        }
    }

    /**
     * Potongan uang muka subkon on the opname behind this bill — the subcon
     * mirror of approvedAdvanceTotalsFor(). The bill was priced NET of it
     * (createFromSubconClaim), so approve() adds it back into the gross cost
     * leg and credits it out of the advance account, exactly like a PO final
     * bill consuming its uang muka; advance_applied_amount then records it and
     * cancel() gives it back, both unchanged.
     *
     * Read through the schema, guarded, so Finance still approves bills on an
     * installation without the Subcontract module — the same reason
     * assertRetentionNotReleased() reads scm_ tables raw.
     */
    private function subconAdvanceRecovery(ApBill $bill): float
    {
        if ($bill->subcontract_claim_id === null
            || ! Schema::hasTable('scm_progress_claims')
            || ! Schema::hasColumn('scm_progress_claims', 'advance_recovery_amount')) {
            return 0.0;
        }

        return round((float) DB::table('scm_progress_claims')
            ->where('id', $bill->subcontract_claim_id)
            ->value('advance_recovery_amount'), 2);
    }

    private function advanceAccountCode(): string
    {
        return Erp::string('accounting.purchase_advance_account', self::DEFAULT_PURCHASE_ADVANCE_ACCOUNT);
    }

    /**
     * P4 — potongan kasbon on the mandor opname behind this bill: the labor
     * mirror of subconAdvanceRecovery(). The bill was priced NET of it
     * (createFromLaborClaim), so approve() adds it back into the gross labor
     * cost leg and credits it out of 1-1370. Read through the schema,
     * guarded, for the same module-absence reason as its subcon twin.
     */
    private function laborKasbonDeduction(ApBill $bill): float
    {
        if ($bill->labor_claim_id === null
            || ! Schema::hasTable('scm_labor_claims')) {
            return 0.0;
        }

        return round((float) DB::table('scm_labor_claims')
            ->where('id', $bill->labor_claim_id)
            ->value('kasbon_deduction_amount'), 2);
    }

    /**
     * The kasbon the bill's opname deducts. Failing loudly here is right:
     * a labor bill with a recorded deduction but no resolvable kasbon is a
     * broken fact, and posting past it would credit 1-1370 against nothing.
     */
    private function laborClaimKasbonOrFail(ApBill $bill): Kasbon
    {
        $kasbonId = DB::table('scm_labor_claims')
            ->where('id', $bill->labor_claim_id)
            ->value('kasbon_id');

        if ($kasbonId === null) {
            throw new LogicException(
                "Opname mandor di balik tagihan {$bill->code} mencatat potongan kasbon "
                .'tanpa menunjuk kasbonnya; perbaiki opnamenya lebih dulu.'
            );
        }

        return Kasbon::query()->findOrFail((int) $kasbonId);
    }

    private function kasbons(): KasbonService
    {
        // Resolved lazily rather than constructor-injected: KasbonService
        // pulls the petty-cash stack with it, which every non-labor bill
        // (the overwhelming majority) never needs.
        return app(KasbonService::class);
    }

    // ------------------------------------------------------------ clearing (GR/IR)

    /**
     * The cost bucket this bill charges: what the operator stated, otherwise
     * derived from the source document (subcon opname => subcon; PO or goods
     * receipt => material; anything else => overhead).
     *
     * The derivation alone was wrong for everything a PO can buy that is not
     * material. A crane hired for Rp 180.000.000 through a services PO — no
     * item_id on any line, so no goods receipt and no stock sub-ledger row —
     * debited 5-1100 Beban Material Proyek and wrote fin_project_costs with
     * cost_category 'material', putting the RAP comparison Rp 180 juta over on
     * material and Rp 180 juta under on alat for a project that bought no extra
     * material. CostCategory's docblock promises these values line realisasi up
     * against Estimation's budget categories; that is the promise this breaks.
     *
     * Deliberately NOT re-derived from "the PO has no stock line": consultancy,
     * mobilisasi and security are services too, and calling all of them Alat
     * only moves the misclassification. The operator knows what was bought, so
     * the bill carries it.
     */
    private function costCategory(ApBill $bill): CostCategory
    {
        if ($bill->cost_category instanceof CostCategory) {
            return $bill->cost_category;
        }

        return match (true) {
            $bill->subcontract_claim_id !== null => CostCategory::Subcon,
            // P4: a mandor opname bill is wages — the RAP bucket its BOQ
            // lines were budgeted under.
            $bill->labor_claim_id !== null => CostCategory::Labor,
            // P5: a PPK period billing is plant hire — the alat bucket.
            $bill->work_order_billing_id !== null => CostCategory::Equipment,
            $bill->purchase_order_id !== null, $bill->goods_receipt_id !== null => CostCategory::Material,
            default => CostCategory::Overhead,
        };
    }

    /**
     * Debit side of a CLASSIC bill journal (no three-way match):
     *   - project bill                    => 5-xxxx project cost account per category
     *   - non-project STOCK PO, periodic  => 1-1400 Persediaan Material
     *   - everything else non-project     => 6-4100 general opex
     *
     * Capitalising a purchase means asserting the company still holds it. Two
     * facts have to hold for that, and "the bill names a PO" is neither of them:
     *
     *   the PO must genuinely be stock — at least one line naming an inventory
     *       item (prc_purchase_order_items.item_id), the schema's own definition
     *       of a goods line and the same one assertStockCommitmentSettled uses.
     *       A rental or a service PO has no such line: it can never appear in
     *       the stock sub-ledger, so debiting persediaan books an asset that no
     *       warehouse holds and no issue can ever relieve. Measured before this
     *       fix: "Sewa genset kantor" 5.000.000 sat in 1-1400 for ever and the
     *       rental was never expensed;
     *   perpetual inventory must be OFF — under periodic inventory the goods
     *       receipt posts nothing, so the bill is the only entry that can put
     *       the goods on the balance sheet. Under PERPETUAL, a stock PO reaching
     *       this method recorded no clearing at all, and a receipt that debited
     *       persediaan would have recorded exactly what it credited: nothing was
     *       capitalised, so there is no asset here to add to — typically a short
     *       shipment on a closed PO, invoiced in full. That is a cost.
     */
    private function debitAccountCode(ApBill $bill, CostCategory $category): string
    {
        if ($bill->project_id !== null) {
            return $category->cogsAccountCode();
        }

        if ($this->billCoversStockPo($bill) && ! Erp::setting('accounting.perpetual_inventory', true)) {
            return Erp::string('accounting.inventory_account', self::DEFAULT_INVENTORY_ACCOUNT);
        }

        return self::DEFAULT_GENERAL_EXPENSE_ACCOUNT;
    }

    /**
     * Does this bill's purchase order carry at least one STOCK line — one that
     * names an inventory item? Same definition, same table and same guards as
     * assertStockCommitmentSettled(); a bill with no PO is never stock by this
     * test.
     */
    private function billCoversStockPo(ApBill $bill): bool
    {
        if ($bill->purchase_order_id === null) {
            return false;
        }

        if (! class_exists(PurchaseOrder::class) || ! Schema::hasTable('prc_purchase_order_items')) {
            return false; // Procurement absent: nothing proves this is stock
        }

        return DB::table('prc_purchase_order_items')
            ->where('purchase_order_id', $bill->purchase_order_id)
            ->whereNotNull('item_id')
            ->exists();
    }

    /**
     * What this bill has to clear, as (clearing account code => amount).
     *
     * Read entirely from what POSTED goods receipts RECORDED they credited,
     * less what earlier bills already cleared. Nothing here consults the PO's
     * warehouse or the perpetual-inventory switch, which is precisely why the
     * two ends of the chain can no longer disagree:
     *
     *   - a PO with no deliver-to warehouse still clears whatever its receipts
     *     credited;
     *   - a services PO that happens to carry a warehouse has no such receipt,
     *     so the map is empty and the bill takes the classic path;
     *   - flipping accounting.perpetual_inventory between receipt and invoice
     *     changes nothing: the receipt's own record governs.
     *
     * An empty map means "classic treatment". Receipts are consumed oldest
     * first, so a per-receipt override of the clearing account still lands on
     * the account that receipt actually credited.
     *
     * Inventory is read through the tables, guarded, so Finance still approves
     * bills when the Inventory module is absent.
     *
     * @return array<string, float>
     */
    private function recordedClearing(ApBill $bill): array
    {
        if (! $this->receiptClearingAvailable()) {
            return [];
        }

        $query = DB::table('inv_goods_receipts')
            ->whereNull('deleted_at')
            ->where('status', StockDocumentStatus::Posted->value)
            ->whereNotNull('gl_clearing_account')
            ->where('gl_clearing_amount', '>', 0)
            ->orderBy('id');

        if ($bill->goods_receipt_id !== null) {
            $query->where('id', $bill->goods_receipt_id);
        } elseif ($bill->purchase_order_id !== null) {
            // withTrashed by construction: soft-deleting a PO does not un-credit
            // the balance its receipts raised, so the bill must still clear it.
            $query->where('purchase_order_id', $bill->purchase_order_id);
        } else {
            return [];
        }

        $receipts = $query->get(['id', 'purchase_order_id', 'gl_clearing_account', 'gl_clearing_amount']);
        $cleared = $this->clearedAgainstReceipts($receipts, (int) $bill->getKey());

        $outstanding = [];

        foreach ($receipts as $receipt) {
            $amount = round((float) $receipt->gl_clearing_amount, 2);

            if ($cleared > 0.0) {
                $consumed = min($cleared, $amount);
                $cleared = round($cleared - $consumed, 2);
                $amount = round($amount - $consumed, 2);
            }

            if ($amount <= 0.0) {
                continue;
            }

            $code = (string) $receipt->gl_clearing_account;
            $outstanding[$code] = round(($outstanding[$code] ?? 0.0) + $amount, 2);
        }

        return $outstanding;
    }

    /**
     * The debit side of a PARTIAL bill: each named receipt's outstanding
     * clearing slice, read on a locked re-read of the receipt row inside the
     * approval transaction — a purchase return posted between create and
     * approve has decremented gl_clearing_amount, and the slice must clear
     * what the receipt NOW records, not what it recorded when the bill was
     * priced (the difference simply widens the 6-4500 variance, exactly as it
     * does on a whole-PO bill).
     *
     * A named receipt with no recorded clearing at all — posted under periodic
     * inventory — contributes nothing here; if NO receipt recorded one, the
     * empty map sends approve() down the classic path, the same rule
     * recordedClearing() applies to a whole-PO bill.
     *
     * @param  Collection<int, ApBillGoodsReceipt>  $claims
     * @return array{0: array<string, float>, 1: array<int, float>} [account => amount, claim row id => amount]
     */
    private function partialClearing(ApBill $bill, Collection $claims): array
    {
        if (! $this->receiptClearingAvailable()) {
            return [[], []];
        }

        $receipts = DB::table('inv_goods_receipts')
            ->whereIn('id', $claims->pluck('goods_receipt_id')->all())
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'code', 'purchase_order_id', 'status', 'gl_clearing_account', 'gl_clearing_amount']);

        $clearing = [];
        $perClaim = [];

        foreach ($claims as $claim) {
            $receipt = $receipts->first(
                fn (object $row): bool => (int) $row->id === (int) $claim->goods_receipt_id
            );

            // The claim was validated at create; a receipt that has since been
            // deleted or un-posted no longer backs the slice this bill prices.
            if ($receipt === null || $receipt->status !== StockDocumentStatus::Posted->value) {
                throw new LogicException(
                    "Penerimaan barang #{$claim->goods_receipt_id} pada tagihan {$bill->code} tidak lagi "
                    .'berstatus diposting; batalkan tagihan ini dan terbitkan ulang atas penerimaan yang sah.'
                );
            }

            if ($receipt->gl_clearing_account === null) {
                continue; // periodic receipt: nothing was credited, nothing to clear
            }

            $slice = $this->receiptOutstanding($receipt, (int) $bill->getKey());

            if ($slice <= 0.0) {
                continue;
            }

            $code = (string) $receipt->gl_clearing_account;
            $clearing[$code] = round(($clearing[$code] ?? 0.0) + $slice, 2);
            $perClaim[(int) $claim->getKey()] = $slice;
        }

        return [$clearing, $perClaim];
    }

    /**
     * Clearing already taken out against a set of goods receipts.
     *
     * A receipt's credit can be swept by ANY of three routes — a whole-PO
     * bill, a partial bill claiming the receipt, or a bill keyed on the
     * receipt itself — so the arithmetic has to weigh all of them. Keying on
     * one column only made the routes invisible to each other, and a receipt
     * whose GR/IR credit its PO bill had already debited could be billed a
     * second time: the liability ended up carrying a debit balance and the
     * vendor a second payable for one delivery.
     *
     * Written by approve() from what was actually posted, so this is a fact
     * rather than a re-derivation. Partial bills are counted through their
     * per-receipt claim rows and EXCLUDED from the pooled purchase-order arm:
     * their gl_cleared_amount is the sum of named slices, and pooling it
     * oldest-first across the PO would subtract GRN B's sweep from GRN A's
     * outstanding.
     *
     * The purchase-order route only reaches receipts that actually credited the
     * GR/IR account. A receipt that accrued instead — because by the time it
     * arrived its order could no longer be billed — is settled solely by a bill
     * against the receipt itself, and counting the order's bill against it would
     * wrongly declare that accrual already paid.
     *
     * @param  Collection<int, object>  $receipts
     */
    private function clearedAgainstReceipts(Collection $receipts, ?int $excludeBillId): float
    {
        if ($receipts->isEmpty()) {
            return 0.0;
        }

        $grIrAccount = Erp::string('accounting.grn_clearing_account', '2-1150');

        $receiptIds = $receipts->pluck('id')->all();
        $purchaseOrderIds = $receipts
            ->filter(fn (object $receipt): bool => (string) $receipt->gl_clearing_account === $grIrAccount)
            ->pluck('purchase_order_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Partial sweeps, per receipt. Claim rows exist only for live bills
        // (cancel/delete remove them), and cleared_amount stays 0 until the
        // claiming bill is approved.
        $partial = round((float) DB::table('fin_ap_bill_goods_receipts')
            ->whereIn('goods_receipt_id', $receiptIds)
            ->when($excludeBillId !== null, fn ($query) => $query->whereNot('ap_bill_id', $excludeBillId))
            ->sum('cleared_amount'), 2);

        return round($partial + (float) ApBill::query()
            ->where(function ($query) use ($receiptIds, $purchaseOrderIds): void {
                $query->whereIn('goods_receipt_id', $receiptIds);

                if ($purchaseOrderIds !== []) {
                    $query->orWhereIn('purchase_order_id', $purchaseOrderIds);
                }
            })
            ->when($excludeBillId !== null, fn ($query) => $query->whereKeyNot($excludeBillId))
            ->whereNot('status', DocumentStatus::Cancelled->value)
            // Partial bills were counted above through their claim rows.
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('fin_ap_bill_goods_receipts')
                ->whereColumn('fin_ap_bill_goods_receipts.ap_bill_id', 'fin_ap_bills.id'))
            ->sum('gl_cleared_amount'), 2);
    }

    /**
     * Outstanding recorded clearing on one raw goods-receipt row, less what
     * bills other than $excludeBillId already cleared against it — by either
     * route, see clearedAgainstReceipts().
     */
    private function receiptOutstanding(object $receipt, ?int $excludeBillId): float
    {
        if ($receipt->gl_clearing_account === null) {
            return 0.0;
        }

        $cleared = $this->clearedAgainstReceipts(collect([$receipt]), $excludeBillId);

        return round(round((float) $receipt->gl_clearing_amount, 2) - $cleared, 2);
    }

    private function receiptClearingAvailable(): bool
    {
        return class_exists(StockDocumentStatus::class)
            && Schema::hasTable('inv_goods_receipts')
            && Schema::hasColumn('inv_goods_receipts', 'gl_clearing_account');
    }

    /**
     * The one gate left, and it is a procurement control rather than an
     * accounting-method one: a vendor invoice for a PO may not be approved while
     * that PO still has STOCK lines waiting to be delivered.
     *
     * A stock line is one that names an inventory item (prc_purchase_order_items
     * .item_id) — the schema's own definition, "null for non-stock/service
     * lines". That replaces the old "does the PO have a deliver-to warehouse"
     * heuristic, which demanded a goods receipt from rental and service POs
     * raised out of a PR that happened to name a warehouse, and let a materials
     * PO delivered straight to site (no warehouse) through with no gate at all.
     *
     * Why it is the genuinely wrong thing to allow: those goods will arrive
     * later, debit persediaan and then debit project cost when they are issued.
     * Expensing them now on the invoice books the same rupiah twice. Closing the
     * PO is how a buyer says "nothing more is coming", and it makes billing the
     * short shipment legitimate — the three-way match then clears what arrived
     * and books the rest as a purchase difference.
     *
     * Deliberately independent of accounting.perpetual_inventory: matching an
     * invoice to a delivery is correct under either inventory method, and a gate
     * that read the switch would reopen the hole the moment it was toggled.
     *
     * Closing the order is the escape from THIS gate, not from the accounting —
     * assertOrderedStockWasReceived() below runs whatever the order's status is.
     */
    private function assertStockCommitmentSettled(ApBill $bill): void
    {
        if ($bill->purchase_order_id === null) {
            return;
        }

        if (! class_exists(PurchaseOrder::class) || ! Schema::hasTable('prc_purchase_order_items')) {
            return; // Procurement absent: nothing to match against
        }

        /** @var ?PurchaseOrder $po */
        $po = $bill->purchaseOrder()->withTrashed()->first();

        if ($po === null) {
            return;
        }

        // Before the delivery-completeness gate, because "nothing arrived at
        // all" is a different problem with a different remedy, and closing the
        // order — what the message below offers — does not solve it.
        $this->assertOrderedStockWasReceived($bill, $po);

        if ($po->status === DocumentStatus::Closed) {
            return;
        }

        $undelivered = DB::table('prc_purchase_order_items')
            ->where('purchase_order_id', $po->id)
            ->whereNotNull('item_id')
            ->whereColumn('qty_received', '<', 'qty')
            ->exists();

        if ($undelivered) {
            throw new LogicException(
                "Tagihan atas {$po->code} hanya dapat disetujui setelah barang diterima seluruhnya. "
                .'Terima sisa barang atau tutup PO terlebih dahulu.'
            );
        }
    }

    /**
     * Closing a purchase order is not a delivery: an order that expected goods
     * INTO a warehouse and received none of them may not be billed on the
     * classic (expensing) path, whatever its status.
     *
     * T44, reproduced today against a copy of the live demo. PO/2026/III/0002
     * (Rp 115.600.000 of CCTV, switch dan kabel for project 2) is late, so the
     * buyer closes it — the one prc.update click the refusal above names as a
     * way out. BIL/2026/VIII/0003 then approves on the CLASSIC path with
     * gl_cleared_amount 0,00 and project 2's material realisasi jumps from
     * 0 to 115.600.000 for goods still on the vendor's truck. Two weeks later
     * the goods arrive and are booked against the vendor with no PO number —
     * exactly what StockService's own receipt refusal instructs — so the
     * receipt debits persediaan and the issue charges the project a second
     * time: realisasi 231.200.000 and 5-1100 231.200.000 for one Rp 115,6 juta
     * purchase, with a Rp 115.600.000 credit stranded in the 2-1600 accrual
     * nobody will ever clear. RAP realisasi, project profitability, EVM AC and
     * the PSAK 115 cost-to-cost percentage are all doubled on that purchase.
     *
     * ASKED OF THE RECEIPTS, NEVER OF qty_received. A receipt line may carry
     * po_item_id = NULL — a substituted article the order never mentioned, a
     * genuine over-delivery after the ordered quantity is already complete — and
     * neither moves the order's received quantity, though both are real
     * deliveries that post a real receipt. A gate written on qty_received would
     * refuse them; "does a posted goods receipt name this order" cannot, and it
     * is the same fact recordedClearing() reads, so the guard and the journal
     * can never disagree about whether anything arrived.
     *
     * Existence of the receipt, not its clearing amount: a receipt posted while
     * accounting.perpetual_inventory was OFF records no clearing at all, and the
     * goods still arrived (AdvanceAndReceiptClearingTest pins that switch-order
     * down). Asking for the credit would refuse a bill for goods sitting in the
     * warehouse.
     *
     * THE FOUR CONDITIONS, and the shape each one keeps billable:
     *
     *   a project on the bill    what doubles is the classic branch's second
     *       half, the fin_project_costs row: 5-1100 and the project's realisasi
     *       both carry the goods before they exist, and the issue that finally
     *       delivers them charges the same rupiah again. The same order billed
     *       WITHOUT a project lands in 6-4100 with no project ledger behind it
     *       and leaves GL 1-1400 equal to the stock sub-ledger; that shape is
     *       settled deliberately elsewhere (debitAccountCode's docblock,
     *       ApBillApprovalJournalTest and NonStockBillAccountingTest) and is
     *       left exactly as it is. Its own residue — goods arriving off-PO
     *       later, raising a 2-1600 accrual against a purchase already
     *       expensed — is unchanged by this guard;
     *   a deliver-to warehouse   the order says these goods were expected into
     *       stock, so a goods receipt is what proves they arrived. Materials
     *       delivered straight to site never enter a warehouse and never will:
     *       that PO carries no warehouse_id, the bill IS the cost, and no issue
     *       can ever charge them again;
     *   at least one stock line  a rental or a service PO raised from a PR that
     *       happened to name a warehouse can never produce a goods receipt, and
     *       demanding one would freeze its invoice for ever (the same item_id
     *       test billCoversStockPo and the gate above already use);
     *   perpetual inventory      under periodic the receipt posts no journal and
     *       the issue posts no journal, so this bill is the only entry there
     *       ever is — there is no second charge to prevent.
     *
     * An advance never reaches here (approve() calls this only on the final
     * path), so paying uang muka on an undelivered order is untouched.
     */
    private function assertOrderedStockWasReceived(ApBill $bill, PurchaseOrder $po): void
    {
        if ($bill->project_id === null) {
            return;
        }

        if (! $this->receiptClearingAvailable()) {
            return; // Inventory absent: no receipt exists and no issue can charge twice
        }

        if (! Erp::setting('accounting.perpetual_inventory', true)) {
            return;
        }

        if ($po->warehouse_id === null || ! $this->billCoversStockPo($bill)) {
            return;
        }

        if ($this->orderHasPostedReceipt($po)) {
            return;
        }

        throw new LogicException(
            "Tagihan atas {$po->code} hanya dapat disetujui setelah barang diterima: belum ada "
            .'penerimaan barang yang diposting atas pesanan ini, sehingga tidak ada nilai yang dapat '
            .'dicocokkan dan seluruh tagihan akan dibebankan ke proyek atas barang yang belum ada — '
            .'lalu dibebankan lagi ketika barangnya datang dan dipakai. Posting penerimaan barangnya '
            .'lebih dulu; kalau kirimannya sudah dicatat atas nama vendor tanpa nomor PO, tagihkan '
            .'lewat penerimaan barang tersebut; kalau barangnya memang tidak akan datang, pesanan ini '
            .'tidak boleh ditagih ke proyek.'
        );
    }

    /**
     * Has any posted goods receipt been booked against this order?
     *
     * Soft-deleted receipts are excluded and drafts do not count: neither moved
     * a warehouse balance, so neither is evidence that goods arrived.
     */
    private function orderHasPostedReceipt(PurchaseOrder $po): bool
    {
        return DB::table('inv_goods_receipts')
            ->whereNull('deleted_at')
            ->where('status', StockDocumentStatus::Posted->value)
            ->where('purchase_order_id', $po->id)
            ->exists();
    }

    /**
     * The debit side of a three-way-matched bill: clear each account for exactly
     * what the receipts recorded against it, then park the difference between
     * the invoice and the goods in the purchase variance account.
     *
     *   gross > cleared  => the vendor bills more than arrived (higher unit
     *                       price, or a short shipment on a closed PO):
     *                       debit the variance — an extra cost.
     *   gross < cleared  => the vendor bills less than arrived: credit the
     *                       variance — a gain.
     *   gross == cleared => perfect match, the leg is omitted.
     *
     * @param  array<string, float>  $clearing  clearing account code => amount
     * @return array<int, array<string, mixed>>
     */
    private function threeWayMatchLines(ApBill $bill, array $clearing, float $grossDpp): array
    {
        $lines = [];

        foreach ($clearing as $accountCode => $amount) {
            $lines[] = [
                'account_code' => $accountCode,
                'debit' => $amount,
                'description' => $bill->description,
                'project_id' => $bill->project_id,
            ];
        }

        $variance = $this->purchaseVariance($clearing, $grossDpp);

        if ($variance !== 0.0) {
            $lines[] = [
                'account_code' => Erp::string(
                    'accounting.purchase_variance_account',
                    self::DEFAULT_PURCHASE_VARIANCE_ACCOUNT,
                ),
                'debit' => $variance > 0 ? $variance : 0.0,
                'credit' => $variance < 0 ? abs($variance) : 0.0,
                'description' => "Selisih harga pembelian {$bill->code}",
                'project_id' => $bill->project_id,
            ];
        }

        return $lines;
    }

    /**
     * Invoice value minus the value that actually arrived: positive when the
     * vendor bills more than the receipts recorded (a cost), negative when it
     * bills less (a gain). One definition, used by the journal leg and by the
     * project cost row, so the two can never disagree.
     *
     * @param  array<string, float>  $clearing  clearing account code => amount
     */
    private function purchaseVariance(array $clearing, float $grossDpp): float
    {
        return round($grossDpp - round(array_sum($clearing), 2), 2);
    }

    /**
     * Mirror the purchase price variance of a MATCHED bill into the project
     * cost ledger, so realisasi per category and the general ledger report the
     * same rupiah for the same purchase.
     *
     * The category is the bill's own cost bucket — material for a goods PO —
     * which is also the bucket StockService uses when the material is issued.
     * The variance is part of what that material cost the project (the vendor
     * billed above or below the delivery note), so it belongs in the same
     * bucket as the material; splitting it into overhead would move the
     * difference away from the budget line it belongs to in the RAP.
     * A negative variance is recorded as a negative cost, exactly as the GL
     * credits 6-4500.
     */
    private function recordVarianceProjectCost(ApBill $bill, CostCategory $category, float $variance): void
    {
        if ($variance === 0.0) {
            return; // perfect match: no cost, and no row to write
        }

        $this->projectCosts->record(
            (int) $bill->project_id,
            $bill->bill_date->toDateString(),
            $category,
            'ap_bill',
            (int) $bill->id,
            "Selisih harga pembelian {$bill->code}",
            $variance,
        );
    }

    /**
     * PPh liability account, read off the tax row the bill names (2-1210 PPh 21,
     * 2-1220 PPh 23, 2-1230 PPh Final 4(2)).
     *
     * It used to end in a bare `return '2-1220'`, which is how PPh final Pasal
     * 4(2) ended up in Hutang PPh 23. There is no guess left: a bill that
     * withholds anything must name its tax (assertWithholdingIdentified), and a
     * tax that cannot say which liability it settles stops the approval rather
     * than picking one.
     *
     * withTrashed on purpose. Deleting the master row after the bill was
     * approved must not reroute the withholding — the money was withheld under
     * that scheme and is owed under it.
     *
     * The constant is reached only when the bill withholds NOTHING, i.e. for a
     * leg worth Rp 0 that autoPost drops before it resolves an account.
     */
    private function pphLiabilityAccountCode(ApBill $bill): string
    {
        if (round((float) $bill->pph_amount, 2) <= 0.0) {
            return self::DEFAULT_PPH_LIABILITY_ACCOUNT;
        }

        /** @var ?Tax $tax */
        $tax = Tax::withTrashed()
            ->whereKey($bill->pph_tax_id)
            ->with('coaAccount')
            ->first();

        $code = $tax?->coaAccount?->code;

        if ($code === null) {
            throw new LogicException(
                "Jenis PPh pada {$bill->code} belum ditautkan ke akun hutang pajak; "
                .'lengkapi akun COA-nya di Master Data › Pajak sebelum menyetujui tagihan ini.'
            );
        }

        return $code;
    }

    /**
     * The nomor bukti potong this bill will carry for ever, or null when it
     * withholds nothing.
     *
     * Minted ONCE, never re-derived. TaxExportService used to number the slips
     * by their position in the run (`++$sequence`, blocked rows consuming
     * nothing), so re-exporting a masa after keying in the NPWP the blockers
     * card asked for handed BP-202606-0002 to a different vendor for a
     * different amount than the certificate already issued under that number.
     * The vendor cites the number when claiming its PPh credit; it is a legal
     * reference, not a row index.
     *
     * A number already on the bill is kept — a re-approval after a cancellation
     * would otherwise burn a second one — and a cancelled bill keeps its number
     * reserved rather than releasing it for reuse.
     */
    private function buktiPotongNumberFor(ApBill $bill): ?string
    {
        if (filled($bill->bupot_no) || round((float) $bill->pph_amount, 2) <= 0.0) {
            return $bill->bupot_no;
        }

        return BuktiPotongNumber::allocate(
            (int) $bill->bill_date->year,
            (int) $bill->bill_date->month,
        );
    }

    private function assertEditable(ApBill $bill): void
    {
        if (! $bill->status->isEditable()) {
            throw new LogicException(
                "Bill {$bill->code} is {$bill->status->value} and can no longer be edited."
            );
        }
    }
}
