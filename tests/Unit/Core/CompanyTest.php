<?php

namespace Tests\Unit\Core;

use Modules\Core\Models\Company;
use Tests\ErpTestCase;

/**
 * The single-row company profile every printed document reads from.
 */
class CompanyTest extends ErpTestCase
{
    public function test_current_is_null_before_the_profile_is_filled_in(): void
    {
        $this->assertNull(Company::current());
    }

    public function test_current_returns_the_stored_profile(): void
    {
        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'npwp' => '01.234.567.8-901.000',
            'is_pkp' => true,
        ]);

        $company = Company::current();

        $this->assertNotNull($company);
        $this->assertSame('PT Nusantara Karya Integrasi', $company->name);
        $this->assertTrue($company->is_pkp);
    }

    public function test_the_pkp_flag_is_cast_to_a_boolean(): void
    {
        Company::query()->create(['name' => 'CV Belum PKP', 'is_pkp' => 0]);

        $this->assertFalse(Company::current()->is_pkp);
    }
}
