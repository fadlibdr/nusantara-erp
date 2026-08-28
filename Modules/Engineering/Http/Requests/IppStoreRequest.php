<?php

namespace Modules\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Engineering\Enums\IppScope;

class IppStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /**
     * Per-field shape; the same-project rule for referenced submittals and the
     * submit gate live in IppService. Line FKs are validated by existence here
     * because they are this module's own tables.
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'scope' => ['required', Rule::enum(IppScope::class)],
            'location_id' => ['nullable', 'integer', Rule::exists('core_locations', 'id')],
            // Existence, project match and the work-package rule live in
            // IppService::assertWbsTaskIsWorkPackage — the project to check
            // against is a service concern on update, so both requests defer.
            'wbs_task_id' => ['nullable', 'integer'],
            'description' => ['required', 'string', 'max:2000'],
            'planned_start' => ['required', 'string', 'date'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'materials' => ['nullable', 'array'],
            'materials.*.item_id' => ['nullable', 'integer', Rule::exists('inv_items', 'id')],
            'materials.*.description' => ['required', 'string', 'max:200'],
            'materials.*.qty' => ['required', 'numeric', 'min:0.001'],
            'materials.*.unit' => ['required', 'string', 'max:20'],
            'equipment' => ['nullable', 'array'],
            'equipment.*.description' => ['required', 'string', 'max:150'],
            'equipment.*.qty' => ['required', 'integer', 'min:1', 'max:65535'],
            'equipment.*.notes' => ['nullable', 'string', 'max:200'],
            'drawings' => ['nullable', 'array'],
            'drawings.*.drawing_submittal_id' => ['required', 'integer', Rule::exists('eng_drawing_submittals', 'id')],
            'material_approvals' => ['nullable', 'array'],
            'material_approvals.*.material_submittal_id' => ['required', 'integer', Rule::exists('eng_material_submittals', 'id')],
        ];
    }
}
