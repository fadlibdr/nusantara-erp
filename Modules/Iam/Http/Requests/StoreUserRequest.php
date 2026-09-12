<?php

namespace Modules\Iam\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Support\PhoneNumber;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            // P-3a (T3a.3): nomor WhatsApp E.164 (dinormalkan UserService) dan
            // opt-in yang dicatat administrator atas persetujuan di luar aplikasi
            // (WhatsAppConsent, via 'admin').
            'phone_e164' => ['sometimes', 'nullable', 'string', 'max:32', static function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && trim((string) $value) !== '' && ! PhoneNumber::isValid((string) $value)) {
                    $fail(PhoneNumber::MESSAGE);
                }
            }],
            'whatsapp_opt_in' => ['sometimes', 'nullable', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }
}
