<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\ApprovalTrail;
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
            // F-1 — satu perender jejak untuk 25 resource (Core\Http\Resources\
            // ApprovalTrail), supaya "Budi a.n. Sari" muncul di semuanya dan
            // bukan di dua puluh empat di antaranya.
            'approvals' => $this->whenLoaded('approvals', fn (): array => ApprovalTrail::map($this->approvals)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
