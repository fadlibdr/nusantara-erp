<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetCategoryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        $categoryId = $this->route('category')?->id;

        return [
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('ast_categories', 'code')->ignore($categoryId)->whereNull('deleted_at')],
            'name' => ['sometimes', 'string', 'max:100'],
            'useful_life_months_default' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'depreciation_account_hint' => ['nullable', 'string', 'max:20'],
            'accum_account_hint' => ['nullable', 'string', 'max:20'],
            'asset_account_hint' => ['nullable', 'string', 'max:20'],
        ];
    }
}
