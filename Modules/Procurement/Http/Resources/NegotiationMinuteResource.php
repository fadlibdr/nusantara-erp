<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\NegotiationMinuteItem;

class NegotiationMinuteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'rfq_id' => $this->rfq_id,
            'rfq_code' => $this->whenLoaded('rfq', fn () => $this->rfq?->code),
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->whenLoaded('vendor', fn () => $this->vendor?->name),
            'meeting_date' => $this->meeting_date?->toDateString(),
            'location' => $this->location,
            'peserta' => $this->peserta ?? [],
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(
                fn (NegotiationMinuteItem $item): array => [
                    'id' => $item->id,
                    'line_no' => (int) $item->line_no,
                    'rfq_item_id' => $item->rfq_item_id,
                    'description' => $item->description,
                    'qty' => $item->qty,
                    'unit' => $item->unit,
                    'harga_awal' => $item->harga_awal,
                    'harga_nego' => $item->harga_nego,
                ],
            )->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
