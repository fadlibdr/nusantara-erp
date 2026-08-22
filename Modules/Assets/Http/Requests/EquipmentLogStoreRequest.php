<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EquipmentLogStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'deployment_id' => ['required', 'integer', Rule::exists('ast_deployments', 'id')],
            'log_date' => ['required', 'date'],
            // At least one of the two readings, enforced HERE and not as a DB
            // constraint: an empty row is an operator slip the form should
            // answer in words, and required_without names the missing half on
            // both fields at once. A fuel-only row (meter unreadable, glass
            // fogged) and a meter-only row (no refuel today) are both honest.
            'hour_meter' => ['nullable', 'numeric', 'min:0', 'required_without:fuel_liters'],
            'fuel_liters' => ['nullable', 'numeric', 'min:0', 'required_without:hour_meter'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        $sentence = 'Isi hour meter atau liter BBM — baris log tanpa satu pun pembacaan tidak mencatat apa-apa.';

        return [
            'hour_meter.required_without' => $sentence,
            'fuel_liters.required_without' => $sentence,
        ];
    }
}
