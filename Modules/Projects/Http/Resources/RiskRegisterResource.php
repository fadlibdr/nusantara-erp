<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskRegisterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),
            'sort_order' => (int) $this->sort_order,
            'activity' => $this->activity,
            'hazard' => $this->hazard,
            'impact' => $this->impact,
            'likelihood' => (int) $this->likelihood,
            'severity' => (int) $this->severity,
            // Kolom TERSIMPAN hasil hitung service — bukan dihitung ulang di
            // sini, supaya lembar cetak dan API membaca angka yang sama.
            'initial_score' => (int) $this->initial_score,
            // Tingkat DITURUNKAN dari skor lewat banding satu tempat.
            'initial_level' => $this->initialLevel()->value,
            'initial_level_label' => $this->initialLevel()->label(),
            'controls' => $this->controls,
            'residual_likelihood' => $this->residual_likelihood,
            'residual_severity' => $this->residual_severity,
            'residual_score' => $this->residual_score,
            'residual_level' => $this->residualLevel()?->value,
            'residual_level_label' => $this->residualLevel()?->label(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
