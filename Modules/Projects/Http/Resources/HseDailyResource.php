<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HseDailyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),
            'report_date' => $this->report_date?->toDateString(),
            // Tautan turunan (proyek, tanggal) — null bila laporan harian hari
            // itu tidak (atau belum) ada; kodenya ikut supaya layar tidak
            // menebak-nebak nomor.
            'daily_report_id' => $this->daily_report_id,
            'daily_report_code' => $this->whenLoaded('dailyReport', fn () => $this->dailyReport?->code),
            'toolbox_topic' => $this->toolbox_topic,
            'toolbox_attendees' => $this->toolbox_attendees ?? [],
            'notes' => $this->notes,
            'apd' => $this->whenLoaded('apd', fn () => $this->apd->map(fn ($row) => [
                'id' => $row->id,
                'category' => $row->category,
                'qty' => (int) $row->qty,
            ])->all()),
            'findings_count' => $this->whenCounted('findings'),
            'findings' => $this->whenLoaded('findings', fn () => $this->findings->map(fn ($row) => [
                'id' => $row->id,
                'sort_order' => (int) $row->sort_order,
                'finding' => $row->finding,
                'follow_up' => $row->follow_up,
            ])->all()),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
