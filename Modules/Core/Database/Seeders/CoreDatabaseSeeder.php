<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Company;

class CoreDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->updateOrCreate(
            ['name' => 'PT Nusantara Karya Integrasi'],
            [
                'legal_name' => 'PT Nusantara Karya Integrasi',
                'npwp' => '01.234.567.8-012.000', // dummy
                'nib' => '8120001234567',          // dummy
                'is_pkp' => true,
                'sppkp_number' => 'S-000/PKP/WPJ.00/2020', // dummy
                'address' => 'Jl. Raya Cakung Cilincing KM 2 No. 88',
                'city' => 'Jakarta Timur',
                'province' => 'DKI Jakarta',
                'postal_code' => '13910',
                'phone' => '021-4600888',
                'email' => 'info@nusantarakarya.co.id',
                'website' => 'https://nusantarakarya.co.id',
            ],
        );
    }
}
