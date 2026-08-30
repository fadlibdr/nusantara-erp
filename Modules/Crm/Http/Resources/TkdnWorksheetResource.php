<?php

namespace Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Crm\Services\TkdnService;

/**
 * THE SUMMARY IS PART OF THE RESOURCE, not an optional second call.
 *
 * A worksheet row without its percentage-and-coverage pair is a row a screen
 * would have to compute for itself, and the moment two places compute a TKDN
 * number they can disagree. It is also why coverage rides along: a client that
 * could fetch the percentage alone would print the percentage alone.
 */
class TkdnWorksheetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = app(TkdnService::class)->summary($this->resource);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'quotation_id' => $this->quotation_id,
            'quotation' => $this->whenLoaded('quotation', fn () => [
                'id' => $this->quotation->id,
                'code' => $this->quotation->code,
                'title' => $this->quotation->title,
                'total' => $this->quotation->total,
            ]),
            'tender_package_id' => $this->tender_package_id,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($row): array => [
                'id' => $row->id,
                'quotation_item_id' => $row->quotation_item_id,
                'sort_order' => $row->sort_order,
                'cost_group' => $row->cost_group?->value,
                'cost_group_label' => $row->cost_group?->label(),
                'description' => $row->description,
                'amount' => $row->amount,
                'nationality' => $row->nationality?->value,
                'made_in' => $row->made_in?->value,
                'owned_by' => $row->owned_by?->value,
                'domestic_share_pct' => $row->domestic_share_pct,
                'provider_origin' => $row->provider_origin?->value,
                'domestic_factor_pct' => round(app(TkdnService::class)->domesticFactor($row) * 100, 2),
            ])),
            'summary' => $summary,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
