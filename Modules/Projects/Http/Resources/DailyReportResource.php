<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'report_date' => $this->report_date?->toDateString(),
            'weather_am' => $this->weather_am?->value,
            'weather_am_label' => $this->weather_am?->label(),
            'weather_pm' => $this->weather_pm?->value,
            'weather_pm_label' => $this->weather_pm?->label(),
            'manpower_count' => (int) $this->manpower_count,
            'activities' => $this->activities,
            'obstacles' => $this->obstacles,
            'safety_notes' => $this->safety_notes,
            'photos' => $this->photos,
            'created_by' => $this->created_by,
            'materials' => DailyReportMaterialResource::collection($this->whenLoaded('materials')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
