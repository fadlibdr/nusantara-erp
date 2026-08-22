<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Models\Company;

class CompanyController extends ApiController
{
    public function show(): JsonResponse
    {
        return $this->ok(Company::current());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'npwp' => ['nullable', 'string', 'max:30'],
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

        $company = Company::current() ?? new Company;
        $company->fill($data)->save();

        return $this->ok($company, 'Company profile updated');
    }
}
