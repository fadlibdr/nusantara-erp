<?php

namespace Modules\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Engineering\Enums\TransmittalDirection;

class TransmittalStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /**
     * Line kinds are validated for SHAPE here; the closed vocabulary itself
     * (kind => class) and the same-project rule live in TransmittalService,
     * one implementation for store and update.
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'direction' => ['required', Rule::enum(TransmittalDirection::class)],
            'to_party' => ['required', 'string', 'max:200'],
            'transmittal_date' => ['required', 'string', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.kind' => ['required', 'string', 'max:40'],
            'lines.*.document_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:300'],
            'lines.*.remarks' => ['nullable', 'string', 'max:200'],
        ];
    }
}
