<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * category dan work_package tidak ada di sini: keduanya adalah IDENTITAS
 * rangkaian versi sebuah metode. Memindahkan paket kerjanya berarti metode
 * lain, bukan suntingan — dan versi 2 yang duduk di paket berbeda dari versi 1
 * membuat "versi berlaku untuk paket ini" tidak terjawab.
 */
class MethodLibraryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:250'],
            'summary' => ['nullable', 'string'],
            'effective_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
