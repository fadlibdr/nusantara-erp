<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tautan IBPRP, diganti utuh.
 *
 * TANPA Rule::exists('prj_risk_register', …), dan itu disengaja: memvalidasinya
 * di sini akan menuliskan nama tabel modul lain ke dalam FormRequest Crm sambil
 * MELEWATI aturan yang sebenarnya (baris itu harus milik proyek RKK ini).
 * RkkService memeriksa keduanya sekaligus, mentah, di balik Schema::hasTable —
 * satu tempat, dan tetap berlaku bila service dipanggil dari seeder.
 */
class RkkIbprpLinksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ibprp_links' => ['present', 'array'],
            'ibprp_links.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
