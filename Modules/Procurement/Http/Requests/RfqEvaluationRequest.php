<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RfqEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'evaluations' => ['required', 'array', 'min:1'],
            'evaluations.*.vendor_id' => ['required', 'integer'],
            'evaluations.*.rab_amount' => ['nullable', 'numeric', 'min:0'],
            'evaluations.*.offered_amount' => ['nullable', 'numeric', 'min:0'],
            // Skor harga TIDAK diterima dari klien: dihitung dari rasio ke RAB.
            'evaluations.*.mutu_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'evaluations.*.waktu_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'evaluations.*.keuangan_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'evaluations.*.k3_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'evaluations.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
