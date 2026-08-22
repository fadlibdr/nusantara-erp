<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subcontract\Enums\AddendumChangeType;

class AddendumUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            // The parent SPK never moves: an addendum on the wrong SPK is
            // deleted and re-drafted, not re-pointed.
            'subcontract_id' => ['prohibited'],
            'addendum_date' => ['sometimes', 'date'],
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'reason' => ['nullable', Rule::in(['permintaan_pemberi_kerja', 'kondisi_lapangan', 'desain', 'lainnya'])],
            'change_type' => ['sometimes', 'required', Rule::enum(AddendumChangeType::class)],
            'value_change' => ['sometimes', 'numeric', 'not_in:0'],
            'items' => ['sometimes', 'array'], // lines are replaced wholesale
            'items.*.wbs_code' => ['nullable', 'string', 'max:20'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
