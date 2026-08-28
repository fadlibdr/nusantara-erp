<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;

/**
 * Satu baris MATERIAL MASUK HARI INI (FM-10-12): jumlah DITERIMA dan DITOLAK.
 *
 * BUKAN prj_daily_report_materials — itu PEMAKAIAN (qty_used), fakta gudang
 * keluar; ini fakta kedatangan, yang di modul Pengadaan hidup sebagai GRN.
 * goods_receipt_id menunjuk GRN sumbernya bila baris diimpor dari kandidat
 * (rujukan lintas modul tanpa constraint); baris ketikan tangan sah tanpa
 * rujukan — surat jalan yang datang tanpa GRN tetap datang.
 * qty_rejected ≤ qty_received ditegakkan DailyReportService.
 */
class DailyReportReceipt extends BaseModel
{
    protected $table = 'prj_daily_report_receipts';

    protected function casts(): array
    {
        return [
            'qty_received' => 'decimal:3',
            'qty_rejected' => 'decimal:3',
        ];
    }

    public function dailyReport(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
