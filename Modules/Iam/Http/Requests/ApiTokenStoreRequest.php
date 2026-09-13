<?php

namespace Modules\Iam\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Core\Support\TokenScope;
use Modules\Iam\Models\ApiToken;

/**
 * POST iam/me/api-tokens — dua aturan yang roadmap tuliskan, ditegakkan di sini.
 *
 * "abilities = SUBSET IZIN". Bukan "daftar izin yang ada", melainkan subset
 * izin PEMANGGIL: seorang staf gudang tidak boleh mencetak token ber-ability
 * `fin.approve` hanya karena izin itu ada di sistem. Daftar yang sah dibaca
 * dari `getAllPermissionNames()` pemanggil pada saat ini — dan karena
 * penegakan runtime (TokenScope) memeriksa izin penggunanya LAGI pada setiap
 * permintaan, izin yang dicabut besok mencabut aksesnya token besok juga.
 *
 * "kedaluwarsa ≤ 1 tahun". Dikirim sebagai JUMLAH HARI, bukan tanggal: sebuah
 * tanggal harus dibandingkan terhadap zona waktu seseorang, dan batas "satu
 * tahun" yang bergeser satu hari karena WIB lawan UTC adalah persis jenis
 * ketidakjelasan yang tidak boleh ada di kredensial. 1..365 hari, dan
 * tanggalnya dihitung server.
 */
class ApiTokenStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:60'],
            'abilities' => ['required', 'array', 'min:1', 'max:100'],
            'abilities.*' => ['required', 'string', 'max:100'],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:'.ApiToken::MAX_LIFETIME_DAYS],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'Nama token',
            'abilities' => 'Ability',
            'expires_in_days' => 'Masa berlaku',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $requested = array_values(array_unique(array_map(
                static fn ($ability): string => is_string($ability) ? trim($ability) : '',
                (array) $this->input('abilities', []),
            )));

            if ($requested === []) {
                return;
            }

            $held = $this->user()->getAllPermissions()->pluck('name')->all();

            foreach ($requested as $ability) {
                if (! TokenScope::isGrantableAbility($ability)) {
                    $validator->errors()->add(
                        'abilities',
                        "Ability «{$ability}» tidak bisa diberikan kepada sebuah token. Ability adalah nama izin tunggal "
                        .'(mis. «fin.view»); tanda bintang tidak diterima, karena token yang bisa segalanya adalah token '
                        .'tanpa batas.',
                    );

                    continue;
                }

                if (! in_array($ability, $held, true)) {
                    $validator->errors()->add(
                        'abilities',
                        "Anda tidak memegang izin «{$ability}», jadi token Anda tidak bisa memilikinya. "
                        .'Ability sebuah token adalah SUBSET izin pemiliknya — tidak pernah lebih.',
                    );
                }
            }
        });
    }
}
