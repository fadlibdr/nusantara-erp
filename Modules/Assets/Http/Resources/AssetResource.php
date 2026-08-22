<?php

namespace Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category_id' => $this->category_id,
            'category' => AssetCategoryResource::make($this->whenLoaded('category')),
            'brand' => $this->brand,
            'model' => $this->model,
            'serial_no' => $this->serial_no,
            'acquisition_date' => $this->acquisition_date?->toDateString(),
            'acquisition_cost' => $this->acquisition_cost,
            'salvage_value' => $this->salvage_value,
            'useful_life_months' => $this->useful_life_months,
            'depreciation_start_date' => $this->depreciation_start_date?->toDateString(),
            'accumulated_depreciation' => $this->accumulated_depreciation,
            'book_value' => $this->book_value,
            'monthly_depreciation' => $this->monthlyDepreciation(),
            'is_fully_depreciated' => $this->isFullyDepreciated(),
            'current_project_id' => $this->current_project_id,
            'custodian_employee_id' => $this->custodian_employee_id,
            'warehouse_id' => $this->warehouse_id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'disposal_date' => $this->disposal_date?->toDateString(),
            'disposal_value' => $this->disposal_value,
            'disposal_reason' => $this->disposal_reason,
            'notes' => $this->notes,
            'active_deployment' => DeploymentResource::make($this->whenLoaded('activeDeployment')),
            'deployments' => DeploymentResource::collection($this->whenLoaded('deployments')),
            'maintenances' => MaintenanceResource::collection($this->whenLoaded('maintenances')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
