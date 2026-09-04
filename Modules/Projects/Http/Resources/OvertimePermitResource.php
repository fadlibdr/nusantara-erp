<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\OvertimePermitWorker;

class OvertimePermitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'overtime_date' => $this->overtime_date?->toDateString(),
            'start_time' => $this->start_time ? substr((string) $this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr((string) $this->end_time, 0, 5) : null,
            // end < start = past midnight, one decision spelled out in
            // OvertimePermitService::assertTimes and surfaced here so the SPA
            // can say "s/d 02:00 (+1 hari)" instead of looking broken.
            'crosses_midnight' => $this->crossesMidnight(),
            'reason' => $this->reason,
            'total_hours' => $this->whenLoaded('workers', fn () => round((float) $this->workers->sum('hours'), 2)),
            'workers' => $this->whenLoaded('workers', fn () => $this->workers->map(
                fn (OvertimePermitWorker $worker): array => [
                    'id' => $worker->id,
                    'employee_id' => $worker->employee_id,
                    'employee_name' => $worker->employee?->name,
                    'worker_name' => $worker->worker_name,
                    'display_name' => $worker->displayName(),
                    'hours' => (float) $worker->hours,
                ],
            )->all()),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
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
