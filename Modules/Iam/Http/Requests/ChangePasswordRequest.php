<?php

namespace Modules\Iam\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT iam/me/password { current, password, password_confirmation }.
 *
 * current_password:sanctum memeriksa sandi lama terhadap pemegang token yang
 * sedang memanggil — guard disebut eksplisit supaya aturannya tidak bergantung
 * pada guard bawaan yang kebetulan aktif. Kalimat galatnya datang dari
 * lang/id/validation.php ('current_password' + atribut 'current'), bukan dari
 * controller, seperti 216 FormRequest lainnya. min:8 mengikuti
 * StoreUserRequest / UpdateUserRequest — satu batas panjang untuk semua pintu.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
