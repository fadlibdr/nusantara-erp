<?php

namespace Modules\Iam\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Support\PhoneNumber;

/**
 * PUT iam/me/phone { phone_e164, whatsapp_opt_in } (P-3a, T3a.3).
 *
 * phone_e164 boleh kosong (menghapus nomor — dan persetujuannya); bila diisi
 * ia harus bisa dinormalkan menjadi E.164 (PhoneNumber), dan yang DISIMPAN
 * adalah bentuk normalnya, bukan yang diketik. whatsapp_opt_in WAJIB hadir
 * sebagai boolean: pintu ini tidak boleh dipakai mengganti nomor tanpa
 * menyatakan sikap tentang persetujuannya.
 */
class UpdatePhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_e164' => ['present', 'nullable', 'string', 'max:32', static function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && trim((string) $value) !== '' && ! PhoneNumber::isValid((string) $value)) {
                    $fail(PhoneNumber::MESSAGE);
                }
            }],
            'whatsapp_opt_in' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_e164.present' => 'Nomor WhatsApp harus dikirim (boleh kosong untuk menghapusnya).',
            'whatsapp_opt_in.required' => 'Sikap opt-in WhatsApp harus dinyatakan: true (setuju) atau false (tidak/cabut).',
            'whatsapp_opt_in.boolean' => 'Sikap opt-in WhatsApp hanya boleh true atau false.',
        ];
    }

    /** Bentuk normal E.164, atau null. */
    public function normalizedPhone(): ?string
    {
        return PhoneNumber::normalize($this->input('phone_e164'));
    }
}
