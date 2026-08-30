<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RiskRegisterUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        $rules = RiskRegisterStoreRequest::sharedRules();

        // Update parsial: kunci yang tidak dikirim mempertahankan nilai
        // tersimpan; service menghitung ulang skor dari nilai EFEKTIF.
        $rules['activity'] = ['sometimes', 'string', 'max:200'];
        $rules['hazard'] = ['sometimes', 'string', 'max:300'];
        $rules['likelihood'] = ['sometimes', 'integer', 'min:1', 'max:5'];
        $rules['severity'] = ['sometimes', 'integer', 'min:1', 'max:5'];

        return $rules;
    }
}
