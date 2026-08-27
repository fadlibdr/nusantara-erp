<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\Weather;
use Modules\Projects\Rules\UniqueDailyReportDate;

class DailyReportStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'report_date' => [
                'required',
                // 'string' sebelum 'date': angka JSON 20260325 lolos aturan
                // 'date' lalu di-cast model sebagai UNIX timestamp — tanggal
                // 1970 tersimpan diam-diam. Formulir selalu mengirim string.
                'string',
                'date',
                // Satu laporan per proyek per hari, dibandingkan per TANGGAL —
                // lihat prosa di UniqueDailyReportDate untuk cacat string-compare
                // yang digantikannya (SQLite: lolos validasi, 500 di indeks unik).
                new UniqueDailyReportDate($this->filled('project_id') ? (int) $this->input('project_id') : null),
            ],
            'weather_am' => ['nullable', Rule::enum(Weather::class)],
            'weather_pm' => ['nullable', Rule::enum(Weather::class)],
            'manpower_count' => ['required', 'integer', 'min:0'],
            'activities' => ['required', 'string'],
            'obstacles' => ['nullable', 'string'],
            'safety_notes' => ['nullable', 'string'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['string', 'max:500'],
            'materials' => ['nullable', 'array'],
            'materials.*.item_id' => ['required', 'integer', 'min:1'],
            'materials.*.qty_used' => ['required', 'numeric', 'min:0.001'],
            'materials.*.unit' => ['required', 'string', 'max:20'],
        ];
    }
}
