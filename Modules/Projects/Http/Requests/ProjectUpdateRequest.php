<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Enums\ProjectType;

class ProjectUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'boq_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(ProjectType::class)],
            'location' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            // Konsultan MK / pengawas — see ProjectStoreRequest. Clearing it is
            // a real edit (the MK's appointment can end mid-job), so null here
            // means null, unlike 'status' below.
            'consultant_name' => ['nullable', 'string', 'max:255'],
            'consultant_role' => ['nullable', 'string', 'max:60'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'actual_start_date' => ['nullable', 'date'],
            'actual_end_date' => ['nullable', 'date', 'after_or_equal:actual_start_date'],
            'contract_value' => ['nullable', 'numeric', 'min:0'],
            'retention_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            // Nullable: the edit form's select uses projectStatusEditable, which
            // carries no 'Ditutup' option, so editing a CLOSED project submits
            // status: null — that must mean "leave it alone", not a 422 on every
            // typo-fix. The service drops the null before filling.
            'status' => ['sometimes', 'nullable', Rule::enum(ProjectStatus::class)],
            'project_manager_id' => ['nullable', 'integer', 'min:1'],
            'site_manager_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
