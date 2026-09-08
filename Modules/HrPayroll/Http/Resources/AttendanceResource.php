<?php

namespace Modules\HrPayroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HrPayroll\Services\AttendanceClockService;

/**
 * Bentuk satu baris absensi di kawat.
 *
 * Blok `check_in`/`check_out` membawa KALIMATNYA sendiri (`distance_text`,
 * `verdict`, `verdict_text`) dan bukan hanya angkanya. Alasannya sama dengan
 * F-2 (BudgetRealisationService::sideView): begitu sebuah layar boleh menyusun
 * kalimatnya sendiri dari angka mentah, layar KEDUA akan menyusunnya sedikit
 * berbeda, dan yang ketiga akan menulis "0 m" untuk jarak yang tidak pernah
 * terukur. Server menjawab pertanyaannya sekali; setiap pembaca mengulang
 * jawaban yang sama.
 */
class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'code' => $this->employee->code,
                'name' => $this->employee->name,
            ]),
            'date' => $this->date?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),
            'note' => $this->note,
            'recorded_by' => $this->recorded_by,
            'check_in' => $this->side(AttendanceClockService::SIDE_IN),
            'check_out' => $this->side(AttendanceClockService::SIDE_OUT),
            'corrections_count' => $this->whenLoaded('corrections', fn () => $this->corrections->count()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function side(string $side): array
    {
        $at = $this->{"{$side}_at"};
        $distance = $this->{"{$side}_distance_m"};
        $verdict = $this->resource->outsideGeofence($side);

        return [
            'recorded' => $at !== null,
            'at' => $at?->toIso8601String(),
            'time_text' => $at?->format('H:i'),
            'device_at' => $this->{"{$side}_device_at"}?->toIso8601String(),
            'device_time_text' => $this->{"{$side}_device_at"}?->format('H:i'),
            'latitude' => $this->{"{$side}_latitude"} === null ? null : (float) $this->{"{$side}_latitude"},
            'longitude' => $this->{"{$side}_longitude"} === null ? null : (float) $this->{"{$side}_longitude"},
            'accuracy_m' => $this->{"{$side}_accuracy_m"},
            'project_id' => $this->{"{$side}_project_id"},
            'distance_m' => $distance,
            'geofence_m' => $this->{"{$side}_geofence_m"},
            'attachment_id' => $this->{"{$side}_attachment_id"},
            // Tiga keadaan, dan yang ketiga BUKAN "di dalam". Lihat
            // Attendance::outsideGeofence().
            'verdict' => $verdict === null ? 'unknown' : ($verdict ? 'outside' : 'inside'),
            'verdict_text' => $this->verdictText($at, $distance, $verdict),
            'distance_text' => $distance === null ? '—' : AttendanceClockService::distanceText((int) $distance),
        ];
    }

    private function verdictText(mixed $at, mixed $distance, ?bool $verdict): string
    {
        if ($at === null) {
            return 'Belum tercatat';
        }

        if ($distance === null || $verdict === null) {
            return 'Lokasi tidak terukur';
        }

        return $verdict ? 'Di luar lokasi' : 'Di lokasi';
    }
}
