<?php

namespace Modules\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Engineering\Enums\IppScope;

class IppUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            // project_id deliberately absent — an IPP does not change project.
            'scope' => ['sometimes', Rule::enum(IppScope::class)],
            'location_id' => ['nullable', 'integer', Rule::exists('core_locations', 'id')],
            // Deep checks in IppService::assertWbsTaskIsWorkPackage, same as
            // the store request. Listed here because validated() only returns
            // validated keys — omit it and the service never sees the change.
            'wbs_task_id' => ['sometimes', 'nullable', 'integer'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'planned_start' => ['sometimes', 'string', 'date'],
            'duration_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'materials' => ['sometimes', 'array'],
            'materials.*.item_id' => ['nullable', 'integer', Rule::exists('inv_items', 'id')],
            'materials.*.description' => ['required_with:materials', 'string', 'max:200'],
            'materials.*.qty' => ['required_with:materials', 'numeric', 'min:0.001'],
            'materials.*.unit' => ['required_with:materials', 'string', 'max:20'],
            'equipment' => ['sometimes', 'array'],
            'equipment.*.description' => ['required_with:equipment', 'string', 'max:150'],
            'equipment.*.qty' => ['required_with:equipment', 'integer', 'min:1', 'max:65535'],
            'equipment.*.notes' => ['nullable', 'string', 'max:200'],
            'drawings' => ['sometimes', 'array'],
            'drawings.*.drawing_submittal_id' => ['required_with:drawings', 'integer', Rule::exists('eng_drawing_submittals', 'id')],
            'material_approvals' => ['sometimes', 'array'],
            'material_approvals.*.material_submittal_id' => ['required_with:material_approvals', 'integer', Rule::exists('eng_material_submittals', 'id')],
        ];
    }
}
