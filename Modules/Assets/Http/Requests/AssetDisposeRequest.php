<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssetDisposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'disposal_date' => ['required', 'date'],
            // 0 is legitimate: scrap and loss dispose at no proceeds.
            'disposal_value' => ['required', 'numeric', 'min:0'],
            // Required: "dijual", "hilang", "rusak total" — the first thing an
            // auditor asks about a fixed asset leaving the balance sheet, and
            // the disposal journal carries it in its description.
            'reason' => ['required', 'string', 'max:200'],
        ];
    }
}
