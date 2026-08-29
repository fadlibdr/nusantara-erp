<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Procurement\Enums\VendorClassification;
use Modules\Procurement\Enums\VendorStatus;
use Modules\Procurement\Enums\VendorType;

class VendorUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'code' => [
                'sometimes', 'string', 'max:40',
                Rule::unique('prc_vendors', 'code')->ignore($this->route('vendor')),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'is_pkp' => ['nullable', 'boolean'],
            'sppkp_number' => ['nullable', 'string', 'max:50'],
            // Deprecated pair — see VendorStoreRequest; the model syncs them.
            'is_subcontractor' => ['nullable', 'boolean'],
            'vendor_type' => ['sometimes', Rule::enum(VendorType::class)],
            'classification' => ['sometimes', Rule::enum(VendorClassification::class)],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'pic_name' => ['nullable', 'string', 'max:100'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_no' => ['nullable', 'string', 'max:50'],
            'bank_account_name' => ['nullable', 'string', 'max:100'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'status' => ['nullable', Rule::enum(VendorStatus::class)],
        ];
    }
}
