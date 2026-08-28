<?php

namespace Modules\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Engineering\Enums\Discipline;

class DrawingStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'number' => [
                'required', 'string', 'max:60',
                Rule::unique('eng_drawings', 'number')->where('project_id', (int) $this->input('project_id')),
            ],
            'title' => ['required', 'string', 'max:200'],
            'discipline' => ['required', Rule::enum(Discipline::class)],
            // 'string' before 'date': a JSON number would survive 'date' and
            // be cast as a UNIX timestamp (the DailyReportStoreRequest lesson).
            'planned_submit_date' => ['nullable', 'string', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'number.unique' => 'Nomor gambar ini sudah terdaftar pada proyek yang sama.',
        ];
    }
}
