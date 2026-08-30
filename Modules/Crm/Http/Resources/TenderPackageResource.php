<?php

namespace Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenderPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'lead_id' => $this->lead_id,
            'lead' => $this->whenLoaded('lead', fn () => [
                'id' => $this->lead->id,
                'code' => $this->lead->code,
                'name' => $this->lead->name,
                'company_name' => $this->lead->company_name,
            ]),
            'title' => $this->title,
            'owner_name' => $this->owner_name,
            'tender_number' => $this->tender_number,
            'registered_at' => $this->registered_at?->toDateString(),
            'submission_deadline' => $this->submission_deadline?->toDateString(),
            'aanwijzing_date' => $this->aanwijzing_date?->toDateString(),
            'aanwijzing_notes' => $this->aanwijzing_notes,
            'documents' => TenderDocumentResource::collection($this->whenLoaded('documents')),
            // Nomor addendum tertinggi yang tercatat — apa yang harus dijawab
            // layar "sudah sampai addendum berapa".
            'last_addendum_no' => $this->lastAddendumNo(),
            'checklist' => $this->checklist,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
