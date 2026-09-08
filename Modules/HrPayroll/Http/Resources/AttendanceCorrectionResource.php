<?php

namespace Modules\HrPayroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris jejak koreksi absensi.
 *
 * `field_label` ada karena nama kolom bukan bahasa manusia: "check_out_at"
 * tidak memberi tahu siapa pun bahwa jam pulangnya yang dipindahkan.
 */
class AttendanceCorrectionResource extends JsonResource
{
    private const LABELS = [
        'status' => 'Status kehadiran',
        'note' => 'Catatan',
        'project_id' => 'Proyek',
        'check_in_at' => 'Jam masuk (server)',
        'check_out_at' => 'Jam pulang (server)',
        'check_in_device_at' => 'Jam masuk (perangkat)',
        'check_out_device_at' => 'Jam pulang (perangkat)',
        'check_in_latitude' => 'Lintang saat masuk',
        'check_in_longitude' => 'Bujur saat masuk',
        'check_out_latitude' => 'Lintang saat pulang',
        'check_out_longitude' => 'Bujur saat pulang',
        'check_in_distance_m' => 'Jarak ke proyek saat masuk',
        'check_out_distance_m' => 'Jarak ke proyek saat pulang',
        'check_in_accuracy_m' => 'Akurasi fix saat masuk',
        'check_out_accuracy_m' => 'Akurasi fix saat pulang',
        'check_in_geofence_m' => 'Radius berlaku saat masuk',
        'check_out_geofence_m' => 'Radius berlaku saat pulang',
        'check_in_project_id' => 'Proyek acuan jarak saat masuk',
        'check_out_project_id' => 'Proyek acuan jarak saat pulang',
        'check_in_attachment_id' => 'Selfie masuk',
        'check_out_attachment_id' => 'Selfie pulang',
        'recorded_by' => 'Pencatat',
    ];

    private const SOURCES = [
        'update' => 'Koreksi pengawas',
        'bulk' => 'Lembar absensi dikirim ulang',
        'clock' => 'Absen ulang dari ponsel',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attendance_id' => $this->attendance_id,
            'field' => $this->field,
            'field_label' => self::LABELS[$this->field] ?? $this->field,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'reason' => $this->reason,
            'source' => $this->source,
            'source_label' => self::SOURCES[$this->source] ?? $this->source,
            'corrected_by' => $this->corrected_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
