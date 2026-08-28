<?php

namespace Modules\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Engineering\Enums\Discipline;

class DrawingUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        $drawing = $this->route('drawing');

        return [
            // project_id and status deliberately absent: a drawing does not
            // move between projects, and status is a mirror the service moves.
            'number' => [
                'sometimes', 'string', 'max:60',
                Rule::unique('eng_drawings', 'number')
                    ->where('project_id', (int) $drawing?->project_id)
                    ->ignore($drawing?->getKey()),
            ],
            'title' => ['sometimes', 'string', 'max:200'],
            'discipline' => ['sometimes', Rule::enum(Discipline::class)],
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
