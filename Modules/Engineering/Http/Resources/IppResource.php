<?php

namespace Modules\Engineering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IppResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'scope' => $this->scope?->value,
            'scope_label' => $this->scope?->label(),
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->name),
            'location_path' => $this->whenLoaded('location', fn () => $this->location?->path()),
            'wbs_task_id' => $this->wbs_task_id,
            'wbs_task_code' => $this->whenLoaded('wbsTask', fn () => $this->wbsTask?->wbs_code),
            'wbs_task_name' => $this->whenLoaded('wbsTask', fn () => $this->wbsTask?->name),
            'description' => $this->description,
            'planned_start' => $this->planned_start?->toDateString(),
            'duration_days' => (int) $this->duration_days,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            // P8 — revisi generik: diturunkan dari stempel, bukan flag tersimpan.
            'revision' => (int) $this->revision,
            'is_current' => ! $this->isSuperseded(),
            'superseded_by_id' => $this->superseded_by_id,
            'superseded_by_code' => $this->whenLoaded('supersededBy', fn () => $this->supersededBy?->code),
            'materials' => $this->whenLoaded('materials', fn () => $this->materials->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'description' => $line->description,
                'qty' => $line->qty,
                'unit' => $line->unit,
            ])->all()),
            'equipment' => $this->whenLoaded('equipment', fn () => $this->equipment->map(fn ($line) => [
                'id' => $line->id,
                'description' => $line->description,
                'qty' => (int) $line->qty,
                'notes' => $line->notes,
            ])->all()),
            'drawings' => $this->whenLoaded('drawings', fn () => $this->drawings->map(fn ($line) => [
                'id' => $line->id,
                'drawing_submittal_id' => $line->drawing_submittal_id,
                'submittal_code' => $line->drawingSubmittal?->code,
                'drawing_number' => $line->drawingSubmittal?->drawing?->number,
                'revision' => $line->drawingSubmittal?->revision,
                'decision' => $line->drawingSubmittal?->decision?->value,
                'decision_label' => $line->drawingSubmittal?->decision?->label() ?? 'Menunggu keputusan',
            ])->all()),
            'material_approvals' => $this->whenLoaded('materialApprovals', fn () => $this->materialApprovals->map(fn ($line) => [
                'id' => $line->id,
                'material_submittal_id' => $line->material_submittal_id,
                'submittal_code' => $line->materialSubmittal?->code,
                'material_name' => $line->materialSubmittal?->material_name,
                'decision' => $line->materialSubmittal?->decision?->value,
                'decision_label' => $line->materialSubmittal?->decision?->label() ?? 'Menunggu keputusan',
            ])->all()),
            // Jejak persetujuan, bentuk PaymentResource — satu perender di SPA
            // (approvalTimeline) untuk semua dokumen; hanya bila show() memuatnya (T3.3).
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvals->map(fn ($approval): array => [
                'id' => $approval->id,
                'action' => $approval->action,
                'note' => $approval->note,
                'created_at' => $approval->created_at?->toIso8601String(),
                'user' => $approval->relationLoaded('user') && $approval->user !== null
                    ? ['id' => $approval->user->id, 'name' => $approval->user->name]
                    : null,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
