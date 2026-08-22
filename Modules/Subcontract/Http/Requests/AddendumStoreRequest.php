<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subcontract\Enums\AddendumChangeType;

class AddendumStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'subcontract_id' => ['required', 'integer', Rule::exists('scm_subcontracts', 'id')],
            'addendum_date' => ['required', 'date'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'reason' => ['nullable', Rule::in(['permintaan_pemberi_kerja', 'kondisi_lapangan', 'desain', 'lainnya'])],
            // Absent means tambah_kurang (the column's default); present from
            // day one so eskalasi never has to masquerade as added work.
            'change_type' => ['sometimes', 'required', Rule::enum(AddendumChangeType::class)],
            // Signed, and never zero — an addendum that changes nothing is a
            // note, not an amendment. The service enforces the deeper shape
            // rules (positive needs lines, negative carries none).
            'value_change' => ['required', 'numeric', 'not_in:0'],
            'items' => ['sometimes', 'array'],
            'items.*.wbs_code' => ['nullable', 'string', 'max:20'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
