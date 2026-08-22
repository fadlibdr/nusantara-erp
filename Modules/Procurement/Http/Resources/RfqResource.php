<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\RfqItem;
use Modules\Procurement\Models\RfqQuote;
use Modules\Procurement\Models\RfqVendor;

class RfqResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'purchase_requisition_id' => $this->purchase_requisition_id,
            'project_id' => $this->project_id,
            'rfq_date' => $this->rfq_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            // Bentuk datar untuk prefill form Ubah (field multiselect membaca
            // record.vendor_ids); bentuk kaya di bawahnya untuk matriks.
            'vendor_ids' => $this->whenLoaded('vendors', fn () => $this->vendors->pluck('vendor_id')->values()),
            // Undangan: kolom-kolom matriks banding.
            'vendors' => $this->whenLoaded('vendors', fn () => $this->vendors->map(
                fn (RfqVendor $invited): array => [
                    'vendor_id' => (int) $invited->vendor_id,
                    'name' => $invited->vendor?->name,
                    'code' => $invited->vendor?->code,
                    'notes' => $invited->notes,
                ],
            )->values()),
            // Baris + sel harga per vendor: baris-baris matriks banding.
            'items' => $this->whenLoaded('items', fn () => $this->items->map(
                fn (RfqItem $line): array => [
                    'id' => $line->id,
                    'line_no' => (int) $line->line_no,
                    'item_id' => $line->item_id,
                    'boq_item_id' => $line->boq_item_id,
                    'description' => $line->description,
                    'qty' => $line->qty,
                    'unit' => $line->unit,
                    'quotes' => $line->relationLoaded('quotes') ? $line->quotes->map(
                        fn (RfqQuote $quote): array => [
                            'vendor_id' => (int) $quote->vendor_id,
                            'unit_price' => $quote->unit_price,
                            'is_winner' => (bool) $quote->is_winner,
                            'notes' => $quote->notes,
                        ],
                    )->values()->all() : [],
                ],
            )->values()),
            // PO yang sudah lahir dari lembar ini — jejak tindak lanjutnya.
            'purchase_orders' => $this->whenLoaded('purchaseOrders', fn () => $this->purchaseOrders->map(
                fn ($po): array => ['id' => $po->id, 'code' => $po->code, 'vendor_id' => $po->vendor_id],
            )->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
