<?php

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'ipp_id' => $this->ipp_id,
            'ipp_code' => $this->whenLoaded('ipp', fn () => $this->ipp?->code),
            'location_id' => $this->location_id,
            'location_path' => $this->whenLoaded('location', fn () => $this->location?->path()),
            'template_id' => $this->template_id,
            'template_code' => $this->whenLoaded('template', fn () => $this->template?->code),
            'work_package' => $this->whenLoaded('template', fn () => $this->template?->work_package),
            'stage' => $this->whenLoaded('template', fn () => $this->template?->stage?->value),
            'stage_label' => $this->whenLoaded('template', fn () => $this->template?->stage?->label()),
            'inspected_at' => $this->inspected_at?->toDateString(),
            'inspector_employee_id' => $this->inspector_employee_id,
            'inspector_name' => $this->whenLoaded('inspector', fn () => $this->inspector?->name),
            'witness_party' => $this->witness_party?->value,
            'witness_party_label' => $this->witness_party?->label(),
            'passed' => (bool) $this->passed,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            // P8 — revisi generik: diturunkan dari stempel, bukan flag tersimpan.
            'revision' => (int) $this->revision,
            'is_current' => ! $this->isSuperseded(),
            'superseded_by_id' => $this->superseded_by_id,
            'superseded_by_code' => $this->whenLoaded('supersededBy', fn () => $this->supersededBy?->code),
            'results' => $this->whenLoaded('results', fn () => $this->results->map(fn ($row) => [
                'id' => $row->id,
                'template_item_id' => $row->template_item_id,
                'check_text' => $row->templateItem?->check_text,
                'acceptance' => $row->templateItem?->acceptance,
                'tolerance' => $row->templateItem?->tolerance,
                'result' => $row->result?->value,
                'result_label' => $row->result?->label(),
                'remark' => $row->remark,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
