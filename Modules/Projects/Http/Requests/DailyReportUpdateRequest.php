<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\Weather;
use Modules\Projects\Rules\UniqueDailyReportDate;

class DailyReportUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        $report = $this->route('dailyReport');

        return [
            'report_date' => [
                'sometimes',
                // 'string' sebelum 'date': angka JSON 20260325 lolos aturan
                // 'date' lalu di-cast model sebagai UNIX timestamp — tanggal
                // 1970 tersimpan diam-diam. Formulir selalu mengirim string.
                'string',
                'date',
                // Per TANGGAL, laporan ini sendiri dikecualikan — lihat
                // UniqueDailyReportDate untuk cacat string-compare yang
                // digantikan. Proyeknya SELALU milik route model:
                // DailyReportService::update tidak pernah memindahkan laporan
                // antar proyek, dan versi yang memercayai input('project_id')
                // bisa dikecoh — project_id palsu membuat aturan memeriksa
                // proyek yang salah, lolos, lalu pecah 500 di indeks unik
                // proyek yang sebenarnya.
                new UniqueDailyReportDate($report?->project_id, $report?->id),
            ],
            'weather_am' => ['nullable', Rule::enum(Weather::class)],
            'weather_pm' => ['nullable', Rule::enum(Weather::class)],
            'work_start' => ['nullable', 'date_format:H:i'],
            'work_end' => ['nullable', 'date_format:H:i'],
            'lost_hours_reason' => ['nullable', 'string', 'max:300'],
            // nullable: null eksplisit berarti "tidak ada klaim manual" —
            // dengan rincian per jabatan tersimpan, angkanya tetap turunan.
            'manpower_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'activities' => ['sometimes', 'string'],
            'obstacles' => ['nullable', 'string'],
            'safety_notes' => ['nullable', 'string'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['string', 'max:500'],
            'materials' => ['sometimes', 'array'],
            'materials.*.item_id' => ['required', 'integer', 'min:1'],
            'materials.*.qty_used' => ['required', 'numeric', 'min:0.001'],
            'materials.*.unit' => ['required', 'string', 'max:20'],
            // Empat tabel baris FM-10-12 — aturan bentuknya satu sumber
            // dengan store, di DailyReportStoreRequest::lineRules().
            ...DailyReportStoreRequest::lineRules(),
        ];
    }

    public function messages(): array
    {
        return DailyReportStoreRequest::lineMessages();
    }
}
