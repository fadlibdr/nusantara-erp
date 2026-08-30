<?php

namespace Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenderDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tender_package_id' => $this->tender_package_id,
            'sort_order' => $this->sort_order,
            'title' => $this->title,
            'chapter' => $this->chapter,
            'issued_date' => $this->issued_date?->toDateString(),
            'addendum_no' => $this->addendum_no,
            'is_addendum' => $this->isAddendum(),
            'notes' => $this->notes,
        ];
    }
}
