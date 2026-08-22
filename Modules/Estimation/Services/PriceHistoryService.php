<?php

namespace Modules\Estimation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat harga beli satu item, dari data yang sudah ada.
 *
 * inv_items caches one avg_cost and one last_price; est_ahsp caches one
 * overwritten unit_price. The price of every actual purchase, meanwhile, sits
 * in prc_purchase_order_items (what was agreed) and inv_goods_receipt_items
 * (what the goods were valued at when they arrived) — and no screen ever read
 * them side by side, which is Temuan 17: an estimator prices the next bid off
 * a single cached number in a market where besi beton moved 10% in a quarter.
 *
 * NO NEW TABLE, deliberately: this service only READS Procurement's and
 * Inventory's tables from Estimation — the module seam the resep prescribes —
 * behind the same Schema::hasTable guards ProjectService uses for
 * prc_purchase_orders, so a deployment without those modules degrades to an
 * empty series instead of a 500.
 *
 * TWO SOURCES, KEPT APART BY A LABEL. A PO price is the agreement; a GRN
 * unit_cost is the valuation of what actually arrived (freight, partial
 * deliveries, a renegotiated line). They genuinely differ, and averaging them
 * into one anonymous point would hide exactly the gap a purchaser needs to see
 * — so every row names its source and its document.
 */
class PriceHistoryService
{
    /**
     * A ceiling, so an item bought weekly for years cannot swamp the screen:
     * the NEWEST rows are kept — a trend is read from the recent end — and the
     * payload says it was cut rather than truncating in silence.
     */
    private const MAX_ROWS = 300;

    /**
     * Prices somebody actually committed to. Draft/submitted is a number typed
     * into a form; rejected and cancelled are numbers somebody refused.
     */
    private const PO_STATUSES = ['approved', 'closed'];

    /**
     * @return array{
     *   item: ?array<string, mixed>,
     *   as_of: string,
     *   date_from: ?string,
     *   date_to: ?string,
     *   series: array<int, array<string, mixed>>,
     *   summary: array<string, mixed>,
     *   truncated: bool,
     * }
     */
    public function forItem(int $itemId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $rows = array_merge(
            $this->purchaseOrderRows($itemId, $dateFrom, $dateTo),
            $this->goodsReceiptRows($itemId, $dateFrom, $dateTo),
        );

        // Newest first to apply the ceiling, then chronological for the chart.
        usort($rows, fn (array $a, array $b): int => [$b['date'], $b['code']] <=> [$a['date'], $a['code']]);
        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_reverse(array_slice($rows, 0, self::MAX_ROWS));

        return [
            'item' => $this->item($itemId),
            'as_of' => now()->toDateString(),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'series' => $rows,
            'summary' => $this->summary($rows),
            'truncated' => $truncated,
        ];
    }

    // ----------------------------------------------------------- the sources

    /**
     * @return array<int, array<string, mixed>>
     */
    private function purchaseOrderRows(int $itemId, ?string $dateFrom, ?string $dateTo): array
    {
        if (! Schema::hasTable('prc_purchase_order_items') || ! Schema::hasTable('prc_purchase_orders')) {
            return [];
        }

        $query = DB::table('prc_purchase_order_items as i')
            ->join('prc_purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->leftJoin('prc_vendors as v', 'v.id', '=', 'o.vendor_id')
            ->where('i.item_id', $itemId)
            ->whereIn('o.status', self::PO_STATUSES)
            ->whereNull('o.deleted_at');

        $this->betweenDates($query, 'o.order_date', $dateFrom, $dateTo);

        return $query
            ->get(['o.order_date', 'o.code', 'o.status', 'v.name as vendor_name', 'i.qty', 'i.unit', 'i.unit_price'])
            ->map(fn (object $row): array => [
                // SQLite hands date columns back as '2026-03-10 00:00:00'.
                'date' => substr((string) $row->order_date, 0, 10),
                'source' => 'po',
                'code' => $row->code,
                'status' => $row->status,
                'vendor_name' => $row->vendor_name,
                'qty' => (float) $row->qty,
                'unit' => $row->unit,
                'unit_price' => round((float) $row->unit_price, 2),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function goodsReceiptRows(int $itemId, ?string $dateFrom, ?string $dateTo): array
    {
        if (! Schema::hasTable('inv_goods_receipt_items') || ! Schema::hasTable('inv_goods_receipts')) {
            return [];
        }

        $query = DB::table('inv_goods_receipt_items as g')
            ->join('inv_goods_receipts as r', 'r.id', '=', 'g.goods_receipt_id')
            ->leftJoin('prc_vendors as v', 'v.id', '=', 'r.vendor_id')
            ->where('g.item_id', $itemId)
            // Only POSTED receipts are valuations: a draft GRN prices nothing.
            ->where('r.status', 'posted')
            ->whereNull('r.deleted_at');

        $this->betweenDates($query, 'r.receipt_date', $dateFrom, $dateTo);

        return $query
            ->get(['r.receipt_date', 'r.code', 'r.status', 'v.name as vendor_name', 'g.qty', 'g.unit_cost'])
            ->map(fn (object $row): array => [
                'date' => substr((string) $row->receipt_date, 0, 10),
                'source' => 'grn',
                'code' => $row->code,
                'status' => $row->status,
                'vendor_name' => $row->vendor_name,
                'qty' => (float) $row->qty,
                'unit' => null, // GRN lines carry no unit; the item master's applies
                'unit_price' => round((float) $row->unit_cost, 2),
            ])
            ->all();
    }

    // ----------------------------------------------------------- the numbers

    /**
     * The item master beside the trend, so the one cached number everybody used
     * to rely on can be checked against the series that produced it.
     *
     * @return ?array<string, mixed>
     */
    private function item(int $itemId): ?array
    {
        if (! Schema::hasTable('inv_items')) {
            return null;
        }

        $item = DB::table('inv_items')->where('id', $itemId)->first(
            ['id', 'code', 'name', 'unit', 'avg_cost', 'last_price']
        );

        return $item === null ? null : [
            'id' => (int) $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'unit' => $item->unit,
            'avg_cost' => round((float) $item->avg_cost, 2),
            'last_price' => round((float) $item->last_price, 2),
        ];
    }

    /**
     * The average is QUANTITY-WEIGHTED: a 100-kg trial order must not drag the
     * number as far as a 2.000-ton one, because the estimator quotes the next
     * project's volume at it.
     *
     * @param  array<int, array<string, mixed>>  $rows  chronological
     * @return array<string, mixed>
     */
    private function summary(array $rows): array
    {
        if ($rows === []) {
            return [
                'count' => 0,
                'min_price' => null,
                'max_price' => null,
                'latest_price' => null,
                'latest_date' => null,
                'weighted_avg_price' => null,
            ];
        }

        $prices = array_column($rows, 'unit_price');
        $value = 0.0;
        $qty = 0.0;

        foreach ($rows as $row) {
            $value += $row['unit_price'] * $row['qty'];
            $qty += $row['qty'];
        }

        $latest = $rows[count($rows) - 1];

        return [
            'count' => count($rows),
            'min_price' => min($prices),
            'max_price' => max($prices),
            'latest_price' => $latest['unit_price'],
            'latest_date' => $latest['date'],
            'weighted_avg_price' => $qty > 0
                ? round($value / $qty, 2)
                : round(array_sum($prices) / count($prices), 2),
        ];
    }

    /**
     * Half-open on the upper bound, DanglingDocuments' argument verbatim: the
     * date columns come back with a time component, so a string BETWEEN would
     * drop every document dated on date_to itself.
     */
    private function betweenDates(object $query, string $column, ?string $dateFrom, ?string $dateTo): void
    {
        if ($dateFrom !== null) {
            $query->where($column, '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->where($column, '<', date('Y-m-d', strtotime($dateTo.' +1 day')));
        }
    }
}
