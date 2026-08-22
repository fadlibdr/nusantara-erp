<?php

namespace Modules\Finance\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Assets\Enums\DepreciationRunStatus;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Finance\Enums\KasbonStatus;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Enums\PettyCashVoucherStatus;
use Modules\Finance\Enums\PostingStatus;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Enums\TransferStatus;
use Modules\ServiceDesk\Enums\FieldReportStatus;

/**
 * Every document whose posting date is PINNED inside a fiscal period, listed
 * once, so closing the period can refuse while any of them is still unposted.
 *
 * This is the most valuable of the close blocks, because it is the only one
 * that catches "the accountant was mid-thought". A journal voucher dated
 * 2026-06-30 and left in draft can be posted right up to the moment June
 * closes; the instant it does, JournalService::assertPeriodOpen refuses it
 * forever, and the document becomes an orphan nobody ever opens again. Each one
 * is satisfiable in a single click: post it, delete it, or re-date it.
 *
 * TWO DELIBERATE EXCLUSIONS.
 *
 *  - REJECTED documents. A rejected bill is editable, so re-dating it is the
 *    normal correction and not a close problem. Blocking on it would make a
 *    close hostage to a document somebody has already decided against.
 *  - scm_progress_claims. An approved opname raises its AP bill dated when the
 *    BILL is created, not when the claim period was, so an unbilled claim from
 *    June does not pin anything inside June. The bill it eventually raises is
 *    caught here on its own date.
 *
 * The stock-moving sources — the four inventory documents and the field report
 * whose acknowledgement raises one — apply only under perpetual inventory. Under
 * the periodic method a stock movement writes no ledger row at all, so its date
 * is not pinned to a fiscal period and closing costs it nothing.
 *
 * In the taste of Modules\Core\Support\AuditedModels: one declarative list, so
 * the next document type that pins a date is added in one place rather than
 * discovered missing after a month has been closed on top of it.
 */
class DanglingDocuments
{
    /**
     * table => [label, date column, statuses that mean "not yet in the ledger",
     *           SPA link, perpetual-inventory only?]
     *
     * The two run tables are keyed by period_year+period_month instead of a
     * date column; they are marked with a null date column and handled below.
     *
     * @var array<int, array<string, mixed>>
     */
    private const SOURCES = [
        [
            'table' => 'fin_journals',
            'label' => 'Jurnal',
            'date' => 'journal_date',
            'statuses' => [PostingStatus::Draft->value],
            'link' => 'r/finance/journals',
            'soft_deletes' => true,
            'perpetual_only' => false,
        ],
        [
            'table' => 'fin_ar_invoices',
            'label' => 'Invoice termin',
            'date' => 'invoice_date',
            'statuses' => [DocumentStatus::Draft->value, DocumentStatus::Submitted->value],
            'link' => 'r/finance/ar-invoices',
            'soft_deletes' => true,
            'perpetual_only' => false,
        ],
        [
            'table' => 'fin_ap_bills',
            'label' => 'Tagihan vendor',
            'date' => 'bill_date',
            'statuses' => [DocumentStatus::Draft->value, DocumentStatus::Submitted->value],
            'link' => 'r/finance/ap-bills',
            'soft_deletes' => true,
            'perpetual_only' => false,
        ],
        [
            /*
             * Package 7 gave outgoing payments a submitted and an approved
             * state between draft and posted. All three are unposted, and all
             * three carry a payment_date that a close would strand — an
             * APPROVED disbursement dated 2026-06-28 is the worst of them,
             * because somebody has already agreed to pay it.
             */
            'table' => 'fin_payments',
            'label' => 'Pembayaran',
            'date' => 'payment_date',
            'statuses' => [
                PaymentStatus::Draft->value,
                PaymentStatus::Submitted->value,
                PaymentStatus::Approved->value,
            ],
            'link' => 'r/finance/payments',
            'soft_deletes' => true,
            'perpetual_only' => false,
        ],
        [
            /*
             * Kas kecil: only DRAFT bons pin their voucher_date. A POSTED bon
             * is already in the ledger, and a CANCELLED one was reversed —
             * neither has anything left to strand.
             */
            'table' => 'fin_petty_cash_vouchers',
            'label' => 'Voucher kas kecil',
            'date' => 'voucher_date',
            'statuses' => [PettyCashVoucherStatus::Draft->value],
            'link' => 'r/finance/petty-cash-vouchers',
            'soft_deletes' => true,
            'perpetual_only' => false,
        ],
        [
            /*
             * Kasbon: DRAFT only, deliberately. An ISSUED kasbon does not
             * block a close — its advance is already posted (Dr 1-1370) and
             * the unsettled balance is a genuinely outstanding receivable,
             * correctly stated at period end; demanding settlement before
             * close would force fake pertanggungjawaban on real month
             * boundaries. Settlement itself is entered and posted in ONE
             * transaction (KasbonService::settle), so it can never dangle.
             */
            'table' => 'fin_kasbons',
            'label' => 'Kasbon',
            'date' => 'advance_date',
            'statuses' => [KasbonStatus::Draft->value],
            'link' => 'r/finance/kasbon',
            'soft_deletes' => true,
            'perpetual_only' => false,
        ],
        [
            'table' => 'hr_payroll_runs',
            'label' => 'Payroll',
            'date' => null,
            'statuses' => [DocumentStatus::Draft->value, DocumentStatus::Submitted->value],
            'link' => 'r/hr/payroll-runs',
            'soft_deletes' => true,
            'perpetual_only' => false,
        ],
        [
            'table' => 'ast_depreciation_runs',
            'label' => 'Penyusutan',
            'date' => null,
            'statuses' => [DepreciationRunStatus::Draft->value],
            'link' => 'r/assets/depreciation-runs',
            'soft_deletes' => false,
            'perpetual_only' => false,
        ],
        [
            'table' => 'inv_goods_receipts',
            'label' => 'Penerimaan barang',
            'date' => 'receipt_date',
            'statuses' => [StockDocumentStatus::Draft->value],
            'link' => 'r/inventory/goods-receipts',
            'soft_deletes' => true,
            'perpetual_only' => true,
        ],
        [
            'table' => 'inv_issues',
            'label' => 'Pengeluaran barang',
            'date' => 'issue_date',
            'statuses' => [StockDocumentStatus::Draft->value],
            'link' => 'r/inventory/issues',
            'soft_deletes' => true,
            'perpetual_only' => true,
        ],
        [
            /*
             * Retur material dari proyek: a draft pins its return_date exactly
             * the way a draft bon does — StockService::postIssueReturn() asks
             * assertStockPeriodOpen() about that date, so the moment the month
             * closes the retur can never be posted as written. Perpetual only,
             * like every stock document here: under periodic the posting
             * raises no journal and the period gate does not bind its date.
             */
            'table' => 'inv_issue_returns',
            'label' => 'Retur material',
            'date' => 'return_date',
            'statuses' => [StockDocumentStatus::Draft->value],
            'link' => 'r/inventory/issue-returns',
            'soft_deletes' => true,
            'perpetual_only' => true,
        ],
        [
            // Retur pembelian: same shape — a draft pins return_date through
            // StockService::postPurchaseReturn()'s period gate.
            'table' => 'inv_purchase_returns',
            'label' => 'Retur pembelian',
            'date' => 'return_date',
            'statuses' => [StockDocumentStatus::Draft->value],
            'link' => 'r/inventory/purchase-returns',
            'soft_deletes' => true,
            'perpetual_only' => true,
        ],
        [
            'table' => 'inv_stock_adjustments',
            'label' => 'Opname persediaan',
            'date' => 'adjustment_date',
            'statuses' => [DocumentStatus::Draft->value, DocumentStatus::Submitted->value],
            'link' => 'r/inventory/stock-adjustments',
            'soft_deletes' => true,
            'perpetual_only' => true,
            // approveAndPost() is one transaction, so an approved adjustment
            // with no posted_at means that transaction did not finish. Rare,
            // and exactly the kind of residue a close must not bury.
            'approved_unposted' => true,
        ],
        [
            /*
             * Transfer antar gudang, for TWO different reasons, which is why
             * both unfinished statuses are listed.
             *
             * A DRAFT transfer pins its transfer_date the way a draft bon does:
             * sendTransfer() asks assertStockPeriodOpen() about that date, so the
             * moment the month closes the transfer can never be sent as written.
             *
             * An IN-TRANSIT one no longer strands — receiveTransfer() lands the
             * arrival on today once the send month has shut, which is the whole
             * point of that change — but it is the one state in which the stock
             * sub-ledger provably does NOT equal GL 1-1400: the goods have left
             * one warehouse balance and not reached the other while the ledger
             * still carries them. 200 zak Semen Portland on the road from
             * WH-PUSAT to WH-SITE is Rp 12.400.000 of persediaan that the closed
             * month's stock valuation cannot show, and the arrival row then lands
             * in the NEXT month — one movement split across a close nobody was
             * told about. erp:inventory-method-check prints the same figure as its
             * reconciling item; this is the close hearing about it in time to
             * decide. Satisfiable in one click, like every other entry here:
             * receive the goods, or delete the draft.
             */
            'table' => 'inv_transfers',
            'label' => 'Transfer gudang',
            'date' => 'transfer_date',
            'statuses' => [TransferStatus::Draft->value, TransferStatus::InTransit->value],
            'link' => 'r/inventory/transfers',
            'soft_deletes' => true,
            'perpetual_only' => true,
        ],
        [
            /*
             * Laporan lapangan. A field report looks like a ServiceDesk
             * document and is one — right up to the customer's signature, which
             * FieldReportService::acknowledge() turns into a POSTED inventory
             * issue dated on report_date ("Dated on the visit, not on the
             * click … and that is the date the GL period check must judge").
             * So a submitted report carrying parts pins its date exactly the
             * way a draft bon does, and the registry never knew.
             *
             * PM/2026/VI/0007, submitted 2026-06-20 with 3 x ITM-0004 fitted on
             * a customer roof: June closes on 5 July because this item counted
             * zero, the customer signs on 8 July, and acknowledge throws
             * "Periode fiskal 2026-06 sudah ditutup" from then on. The report
             * cannot be re-dated or deleted either (FieldReportStatus::isEditable
             * is Draft-only), so all three escapes the docblock above promises
             * are shut. Finance can still reopen June and let it through — but
             * only until a posted PSAK 115 run measures the month, after which
             * PeriodCloseService::reopenRefusal() refuses for ever and the three
             * units sit on WH-PUSAT's books and in 1-1400 until an opname.
             *
             * SCOPED TO REPORTS THAT CARRY PARTS, the same way the adjustments
             * entry above narrows itself: a signature-only report issues nothing
             * and pins nothing, and blocking a close on it would be the theatre
             * this list exists to avoid.
             */
            'table' => 'svc_field_reports',
            'label' => 'Laporan lapangan',
            'date' => 'report_date',
            'statuses' => [FieldReportStatus::Submitted->value],
            'link' => 'r/servicedesk/field-reports',
            'soft_deletes' => true,
            'perpetual_only' => true,
            'has_lines_in' => ['svc_field_report_parts', 'field_report_id'],
        ],
    ];

    /**
     * Unposted documents pinned inside the period, per source.
     *
     * @return array<int, array{source: string, label: string, count: int, codes: array<int, string>, link: string}>
     */
    public static function scan(int $year, int $month): array
    {
        $perpetual = Erp::bool('accounting.perpetual_inventory', true);
        $start = sprintf('%04d-%02d-01', $year, $month);

        /*
         * HALF-OPEN, not BETWEEN. Every date column here is cast to `date` on
         * its model, and Laravel writes a cast date through the connection's
         * datetime format — so fin_journals.journal_date holds
         * "2026-06-30 00:00:00", which sorts AFTER the string "2026-06-30".
         * whereBetween(start, end) therefore missed every document dated on the
         * last day of the month, which is the single most common date a
         * month-end journal carries.
         */
        $before = CarbonImmutable::create($year, $month, 1)->addMonth()->toDateString();

        $found = [];

        foreach (self::SOURCES as $source) {
            if ($source['perpetual_only'] && ! $perpetual) {
                continue;
            }

            $query = DB::table($source['table']);

            if ($source['date'] === null) {
                $query->where('period_year', $year)->where('period_month', $month);
            } else {
                $query->where($source['date'], '>=', $start)->where($source['date'], '<', $before);
            }

            if ($source['soft_deletes']) {
                $query->whereNull('deleted_at');
            }

            /*
             * Some documents only pin a date when they carry lines: a field
             * report with no spare parts issues no stock and raises no journal,
             * so its report_date binds nothing.
             */
            if (isset($source['has_lines_in'])) {
                [$lineTable, $foreignKey] = $source['has_lines_in'];

                $query->whereExists(fn ($lines) => $lines
                    ->from($lineTable)
                    ->whereColumn($lineTable.'.'.$foreignKey, $source['table'].'.id'));
            }

            $statuses = $source['statuses'];
            $approvedUnposted = $source['approved_unposted'] ?? false;

            $query->where(function ($sub) use ($statuses, $approvedUnposted): void {
                $sub->whereIn('status', $statuses);

                if ($approvedUnposted) {
                    $sub->orWhere(fn ($or) => $or
                        ->where('status', DocumentStatus::Approved->value)
                        ->whereNull('posted_at'));
                }
            });

            $rows = $query->orderBy('code')->limit(200)->get(['code', 'status']);

            if ($rows->isEmpty()) {
                continue;
            }

            $found[] = [
                'source' => $source['table'],
                'label' => $source['label'],
                'count' => $rows->count(),
                'codes' => $rows->take(5)
                    ->map(fn ($row): string => $row->code.' ('.self::statusLabel((string) $row->status).')')
                    ->values()->all(),
                'link' => $source['link'],
            ];
        }

        return $found;
    }

    public static function total(array $scan): int
    {
        return array_sum(array_column($scan, 'count'));
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'draf',
            'submitted' => 'diajukan',
            'approved' => 'disetujui',
            'in_transit' => 'dalam perjalanan',
            default => $status,
        };
    }
}
