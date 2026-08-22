<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\Weather;

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
                'date',
                // One report per project per day.
                Rule::unique('prj_daily_reports', 'report_date')
                    ->where('project_id', (int) $this->input('project_id'))
                    ->whereNull('deleted_at'),
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
