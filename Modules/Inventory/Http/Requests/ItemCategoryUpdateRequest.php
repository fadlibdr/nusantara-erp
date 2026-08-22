<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemCategoryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        $categoryId = $this->route('itemCategory')?->id;

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('inv_item_categories', 'code')->ignore($categoryId)],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('inv_item_categories', 'id'),
                Rule::notIn([$categoryId]), // a category cannot be its own parent
            ],
        ];
    }
}
