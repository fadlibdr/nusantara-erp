<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgressMeasurementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'contract_id' => $this->contract_id,
            'contract_code' => $this->whenLoaded('contract', fn () => $this->contract?->code),
            'measurement_no' => $this->measurement_no,
            // What the owner-claim picker shows under the OPN code: two opnames
            // of one contract differ only by sequence and period, and a picker
            // listing three bare codes is a picker somebody guesses at.
            'picker_label' => $this->whenLoaded('project', fn (): string => sprintf(
                '%s · opname ke-%d · s/d %s',
                (string) ($this->project?->code ?? '—'),
                (int) $this->measurement_no,
                $this->period_end?->format('d-m-Y') ?? '—',
            )),
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'period_amount' => $this->period_amount,
            'cumulative_amount' => $this->cumulative_amount,
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'items' => ProgressMeasurementItemResource::collection($this->whenLoaded('items')),
            'import_source' => $this->import_source,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
