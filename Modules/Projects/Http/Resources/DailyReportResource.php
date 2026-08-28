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
            // Kolom TIME dinormalkan ke 'HH:MM' — SQLite menyimpan apa yang
            // ditulis ('08:00'), MySQL mengembalikan '08:00:00'.
            'work_start' => $this->work_start === null ? null : substr($this->work_start, 0, 5),
            'work_end' => $this->work_end === null ? null : substr($this->work_end, 0, 5),
            'lost_hours_reason' => $this->lost_hours_reason,
            'manpower_count' => (int) $this->manpower_count,
            'activities' => $this->activities,
            'obstacles' => $this->obstacles,
            'safety_notes' => $this->safety_notes,
            'photos' => $this->photos,
            'created_by' => $this->created_by,
            'locked_at' => $this->locked_at?->toIso8601String(),
            'locked' => $this->locked_at !== null,
            'materials' => DailyReportMaterialResource::collection($this->whenLoaded('materials')),
            'manpower' => $this->whenLoaded('manpower', fn () => $this->manpower->map(fn ($row): array => [
                'id' => $row->id,
                'role_key' => $row->role_key?->value,
                'role_label' => $row->role_key?->label(),
                'headcount' => (int) $row->headcount,
                'notes' => $row->notes,
            ])->values()),
            'equipment' => $this->whenLoaded('equipment', fn () => $this->equipment->map(fn ($row): array => [
                'id' => $row->id,
                'asset_id' => $row->asset_id,
                'description' => $row->description,
                'qty' => (int) $row->qty,
                'hours' => $row->hours,
            ])->values()),
            'receipts' => $this->whenLoaded('receipts', fn () => $this->receipts->map(fn ($row): array => [
                'id' => $row->id,
                'goods_receipt_id' => $row->goods_receipt_id,
                'item_id' => $row->item_id,
                'description' => $row->description,
                'qty_received' => $row->qty_received,
                'qty_rejected' => $row->qty_rejected,
                'unit' => $row->unit,
                'rejection_reason' => $row->rejection_reason,
            ])->values()),
            'activity_lines' => $this->whenLoaded('activityLines', fn () => $this->activityLines->map(fn ($row): array => [
                'id' => $row->id,
                'wbs_task_id' => $row->wbs_task_id,
                'description' => $row->description,
                'progress_note' => $row->progress_note,
                'target_note' => $row->target_note,
                'obstacle' => $row->obstacle,
                'sort_order' => (int) $row->sort_order,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
