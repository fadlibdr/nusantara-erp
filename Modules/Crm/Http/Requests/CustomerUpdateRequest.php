<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Rules\ValidNpwp;

class CustomerUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        $customerId = $this->route('customer')?->id;

        return [
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('crm_customers', 'code')->ignore($customerId)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            // P-3b: nilai lama yang dikirim kembali apa adanya bukan penulisan baru (maju-saja).
            'npwp' => ['nullable', 'string', 'max:30', ValidNpwp::unlessUnchanged($this->route('customer')?->npwp)],
            'is_pkp' => ['nullable', 'boolean'],
            'billing_address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'pic_name' => ['nullable', 'string', 'max:100'],
            'pic_phone' => ['nullable', 'string', 'max:30'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
