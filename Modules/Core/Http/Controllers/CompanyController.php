<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Models\Company;
use Modules\Core\Rules\ValidNpwp;

class CompanyController extends ApiController
{
    public function show(): JsonResponse
    {
        return $this->ok(Company::current());
    }

    public function update(Request $request): JsonResponse
    {
        $company = Company::current() ?? new Company;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            // P-3b: pintu tulis NPWP perusahaan — aturan yang sama dengan pelanggan,
            // vendor, pegawai, dan impor master; nilai lama yang dikirim kembali
            // apa adanya bukan penulisan baru (maju-saja).
            'npwp' => ['nullable', 'string', 'max:30', ValidNpwp::unlessUnchanged($company->npwp)],
            'nib' => ['nullable', 'string', 'max:30'],
            'is_pkp' => ['boolean'],
            'sppkp_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:100'],
            'website' => ['nullable', 'string', 'max:100'],
        ]);

        $company->fill($data)->save();

        return $this->ok($company, 'Company profile updated');
    }
}
