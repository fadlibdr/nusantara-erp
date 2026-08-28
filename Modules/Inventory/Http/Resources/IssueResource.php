<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'project_id' => $this->project_id,
            'wbs_task_id' => $this->wbs_task_id,
            // Set when the issue was posted by a field report acknowledgement
            // (svc_field_reports.id); null on a hand-raised bon.
            'field_report_id' => $this->field_report_id,
            // The IPP this bon draws material for (eng_work_permits_ipp.id);
            // the header wbs_task_id above was inherited from it when the bon
            // named the permit and left the task blank.
            'ipp_id' => $this->ipp_id,
            'ipp_code' => $this->whenLoaded('ipp', fn () => $this->ipp?->code),
            'issue_date' => $this->issue_date?->toDateString(),
            'issued_by' => $this->issued_by,
            'purpose' => $this->purpose,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            // Filled by StockService::cancelIssue(); the original posting stays
            // in the ledger and a reversing journal sits beside it.
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->cancelled_by,
            'cancellation_reason' => $this->cancellation_reason,
            'items' => IssueItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
