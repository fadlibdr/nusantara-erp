<?php

namespace Modules\Estimation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Estimation\Enums\AhspCategory;
use Modules\Estimation\Enums\ComponentType;

class AhspStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('est_ahsp', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:20'],
            'category' => ['required', Rule::enum(AhspCategory::class)],
            'overhead_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
            'components' => ['sometimes', 'array'],
            'components.*.component_type' => ['required', Rule::enum(ComponentType::class)],
            'components.*.name' => ['required', 'string', 'max:255'],
            'components.*.item_id' => ['nullable', 'integer', 'min:1'],
            'components.*.unit' => ['required', 'string', 'max:20'],
            'components.*.coefficient' => ['required', 'numeric', 'min:0'],
            'components.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
