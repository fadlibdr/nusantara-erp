<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Enums\LocationKind;

class LocationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        $id = $this->route('location')?->getKey();

        return [
            // project_id deliberately absent: moving a location (and silently
            // its subtree) to another project would re-address every document
            // already pointing at it. A wrong project means delete + recreate.
            'parent_id' => ['nullable', 'integer', Rule::exists('core_locations', 'id')],
            'kind' => ['sometimes', Rule::enum(LocationKind::class)],
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('core_locations', 'code')->ignore($id)],
            'name' => ['sometimes', 'string', 'max:150'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
