<?php

namespace Tests\Unit\Crm;

use App\Models\User;
use Modules\Crm\Models\Customer;
use Modules\Crm\Services\ContractService;
use Modules\Crm\Services\QuotationService;

/**
 * Hand-built CRM fixtures shared by the quotation / contract unit tests.
 *
 * Deliberately dumb: it only assembles rows. It never computes an expected
 * number — every expectation is spelled out, with its arithmetic, in the test
 * that asserts it.
 */
trait CrmFixtures
{
    protected function quotations(): QuotationService
    {
        return app(QuotationService::class);
    }

    protected function contracts(): ContractService
    {
        return app(ContractService::class);
    }

    protected function makeCustomer(array $data = []): Customer
    {
        return Customer::query()->create(array_merge([
            'name' => 'PT Graha Sentosa Propertindo',
            'legal_name' => 'PT Graha Sentosa Propertindo',
            'npwp' => '01.234.567.8-012.000',
            'is_pkp' => true,
            'city' => 'Jakarta Selatan',
            'payment_term_days' => 30,
            'status' => 'active',
        ], $data));
    }

    protected function makeUser(string $email = 'estimator@test.local'): User
    {
        return User::query()->create([
            'name' => 'Rina Wijaya',
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
    }
}
