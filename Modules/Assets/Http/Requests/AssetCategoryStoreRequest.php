<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetCategoryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('ast_categories', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:100'],
            'useful_life_months_default' => ['required', 'integer', 'min:1', 'max:600'],
            'depreciation_account_hint' => ['nullable', 'string', 'max:20'],
            'accum_account_hint' => ['nullable', 'string', 'max:20'],
            'asset_account_hint' => ['nullable', 'string', 'max:20'],
        ];
    }
}
