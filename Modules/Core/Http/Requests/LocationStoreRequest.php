<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Enums\LocationKind;

class LocationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /**
     * Per-field shape only. The rules that COMPARE — parent on the same
     * project, no cycle — live on the Location model's saving hook, because
     * the master-data importer writes rows without passing through here.
     */
    public function rules(): array
    {
        return [
            // Rule::exists on prj_projects is a read of another module's table,
            // which cross-module reads allow (CONVENTIONS §3) — the same check
            // WorkPermitStoreRequest makes.
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'parent_id' => ['nullable', 'integer', Rule::exists('core_locations', 'id')],
            'kind' => ['required', Rule::enum(LocationKind::class)],
            'code' => ['required', 'string', 'max:40', Rule::unique('core_locations', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
