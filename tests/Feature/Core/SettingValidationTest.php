<?php

namespace Tests\Feature\Core;

use Illuminate\Testing\TestResponse;
use Modules\Core\Services\SettingService;
use Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use Tests\ErpTestCase;

/**
 * PUT /api/core/settings refuses anything the registry does not describe.
 * Every refusal must be a 422 that persists nothing at all — an operator who
 * fat-fingers one field must not end up with half a batch applied.
 */
class SettingValidationTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->adminUser(), 'sanctum');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function putSettings(array $settings): TestResponse
    {
        return $this->putJson('/api/core/settings', ['settings' => $settings]);
    }

    /**
     * The request is refused, the error is attributed to the offending key, and
     * nothing at all was written.
     *
     * @param  array<string, mixed>  $settings
     */
    private function assertRejected(array $settings, string $errorKey): void
    {
        $response = $this->putSettings($settings);

        $response->assertStatus(422);
        $this->assertArrayHasKey(
            'settings.'.$errorKey,
            (array) $response->json('errors'),
            "Expected a validation error attributed to [{$errorKey}].",
        );
        $this->assertDatabaseCount('core_settings', 0);
    }

    public function test_it_rejects_a_key_outside_the_registry(): void
    {
        $response = $this->putSettings(['tax.made_up_rate' => 5]);

        $response->assertStatus(422);
        $this->assertSame(
            ['Parameter tax.made_up_rate tidak dikenal.'],
            $response->json('errors')['settings.tax.made_up_rate'],
        );
        $this->assertDatabaseCount('core_settings', 0);
    }

    public function test_it_rejects_a_percent_above_one_hundred(): void
    {
        $this->assertRejected(['tax.ppn_rate' => 150], 'tax.ppn_rate');
    }

    public function test_it_rejects_a_negative_percent(): void
    {
        $this->assertRejected(['tax.ppn_rate' => -1], 'tax.ppn_rate');
    }

    public function test_it_rejects_a_non_numeric_percent(): void
    {
        $this->assertRejected(['tax.ppn_rate' => 'sebelas persen'], 'tax.ppn_rate');
    }

    public function test_it_accepts_the_percent_boundaries(): void
    {
        // 0 and 100 are inside the range; only what is beyond them is refused.
        $this->putSettings(['tax.ppn_rate' => 0])->assertOk();
        $this->assertSame(0.0, (float) app(SettingService::class)->get('tax.ppn_rate'));

        $this->putSettings(['tax.ppn_rate' => 100])->assertOk();
        $this->assertSame(100.0, (float) app(SettingService::class)->get('tax.ppn_rate'));
    }

    public function test_it_rejects_a_negative_currency_amount(): void
    {
        $this->assertRejected(['payroll.bpjs.kesehatan.salary_cap' => -1], 'payroll.bpjs.kesehatan.salary_cap');
    }

    public function test_it_accepts_a_zero_currency_amount(): void
    {
        // Zero is the boundary: a plafon of 0 means "no cap applied".
        $this->putSettings(['payroll.bpjs.kesehatan.salary_cap' => 0])->assertOk();

        $this->assertSame(0.0, (float) app(SettingService::class)->get('payroll.bpjs.kesehatan.salary_cap'));
    }

    public function test_it_rejects_a_select_value_outside_its_options(): void
    {
        // JKK risk classes run 1..5.
        $this->assertRejected(['payroll.bpjs.jkk.default_risk_class' => 6], 'payroll.bpjs.jkk.default_risk_class');
    }

    public function test_it_accepts_the_select_boundaries(): void
    {
        $this->putSettings(['payroll.bpjs.jkk.default_risk_class' => 1])->assertOk();
        $this->assertSame(1, (int) app(SettingService::class)->get('payroll.bpjs.jkk.default_risk_class'));

        $this->putSettings(['payroll.bpjs.jkk.default_risk_class' => 5])->assertOk();
        $this->assertSame(5, (int) app(SettingService::class)->get('payroll.bpjs.jkk.default_risk_class'));
    }

    public function test_it_rejects_a_document_format_without_a_sequence_token(): void
    {
        // Without {N3}/{N4}/{N5} every document of that type would collide.
        $this->assertRejected(['documents.PO' => 'PO/{Y}/{RM}'], 'documents.PO');
    }

    public function test_it_rejects_a_document_format_without_the_year(): void
    {
        // Sequences reset per type per year, so 'PO-{N4}' re-issues PO-0001 on
        // 1 January and the unique code column rejects the document (M6).
        $this->assertRejected(['documents.PO' => 'PO-{N4}'], 'documents.PO');
    }

    public function test_the_document_format_error_explains_why_the_year_is_required(): void
    {
        // A bare "format tidak cocok" would leave the operator guessing: the
        // collision it prevents only happens next January.
        $response = $this->putSettings(['documents.PO' => 'PO-{N4}']);

        $message = $response->json('errors')['settings.documents.PO'][0];

        $this->assertStringContainsString('Pesanan pembelian', $message); // the Indonesian label
        $this->assertStringContainsString('{Y}', $message);
        $this->assertStringContainsString('{N4}', $message);
        $this->assertStringContainsString('direset', $message);
    }

    public function test_it_rejects_a_document_format_carrying_neither_token(): void
    {
        $this->assertRejected(['documents.PO' => 'PO-TETAP'], 'documents.PO');
    }

    public function test_the_tokens_may_appear_in_either_order(): void
    {
        $this->putSettings(['documents.PO' => '{N4}/PO/{Y}'])->assertOk();

        $this->assertSame('{N4}/PO/{Y}', app(SettingService::class)->get('documents.PO'));
    }

    public function test_it_accepts_any_of_the_three_sequence_tokens(): void
    {
        foreach (['PO-{Y}-{N3}', 'PO-{Y}-{N4}', 'PO-{Y}-{N5}'] as $format) {
            $this->putSettings(['documents.PO' => $format])->assertOk();
            $this->assertSame($format, app(SettingService::class)->get('documents.PO'));
        }
    }

    public function test_it_rejects_a_document_format_longer_than_sixty_characters(): void
    {
        // 54 + 3 + 4 = 61 characters, one past the max the code column can hold.
        // Otherwise well formed, so length is the only thing under test.
        $this->assertRejected(['documents.PO' => str_repeat('X', 54).'{Y}{N4}'], 'documents.PO');
    }

    public function test_it_accepts_a_document_format_of_exactly_sixty_characters(): void
    {
        $format = str_repeat('X', 53).'{Y}{N4}'; // 53 + 3 + 4 = 60

        $this->putSettings(['documents.PO' => $format])->assertOk();

        $this->assertSame($format, app(SettingService::class)->get('documents.PO'));
    }

    public function test_it_rejects_an_account_code_that_is_not_in_the_chart_of_accounts(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $this->assertRejected(['accounting.inventory_account' => '9-9999'], 'accounting.inventory_account');
    }

    public function test_it_rejects_an_account_code_that_is_not_postable(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        // 1-1000 "Aset Lancar" is a header account (is_postable = false).
        $response = $this->putSettings(['accounting.inventory_account' => '1-1000']);

        $response->assertStatus(422);
        $this->assertSame(
            ['Akun 1-1000 tidak ada di bagan akun atau bukan akun yang dapat diposting.'],
            $response->json('errors')['settings.accounting.inventory_account'],
        );
        $this->assertDatabaseCount('core_settings', 0);
    }

    public function test_it_accepts_a_postable_account_code(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        // 1-1100 "Kas" is a postable leaf.
        $this->putSettings(['accounting.inventory_account' => '1-1100'])->assertOk();

        $this->assertSame('1-1100', app(SettingService::class)->get('accounting.inventory_account'));
    }

    public function test_an_account_may_be_reset_to_its_default_without_a_chart_lookup(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $this->setSetting('accounting.inventory_account', '1-1100');

        $this->putSettings(['accounting.inventory_account' => null])->assertOk();

        $this->assertDatabaseMissing('core_settings', ['key' => 'accounting.inventory_account']);
        $this->assertSame('1-1400', app(SettingService::class)->get('accounting.inventory_account'));
    }

    public function test_it_rejects_an_out_of_range_integer(): void
    {
        // payroll.overtime.divisor is capped at 400.
        $this->assertRejected(['payroll.overtime.divisor' => 500], 'payroll.overtime.divisor');
    }

    public function test_it_rejects_a_fractional_integer(): void
    {
        $this->assertRejected(['payroll.overtime.divisor' => 173.5], 'payroll.overtime.divisor');
    }

    public function test_it_rejects_a_zero_divisor(): void
    {
        // min is 1: dividing the monthly wage by zero would be a fatal error.
        $this->assertRejected(['payroll.overtime.divisor' => 0], 'payroll.overtime.divisor');
    }

    public function test_it_accepts_the_integer_boundaries(): void
    {
        $this->putSettings(['payroll.overtime.divisor' => 1])->assertOk();
        $this->assertSame(1, (int) app(SettingService::class)->get('payroll.overtime.divisor'));

        $this->putSettings(['payroll.overtime.divisor' => 400])->assertOk();
        $this->assertSame(400, (int) app(SettingService::class)->get('payroll.overtime.divisor'));
    }

    /**
     * A2: the inventory accounting method is not a setting, and the API says so.
     *
     * It used to be a checkbox here, read live when a goods receipt posted and
     * again when the vendor bill was approved, so one flip corrupted the ledger
     * in whichever direction it was flipped. Withdrawing it from the registry is
     * what closes the API too — every rule this endpoint applies comes from the
     * registry, and a key it does not describe is refused.
     *
     * The message must not say "tidak dikenal": the parameter exists, it is an
     * install-time constant in config/erp.php, and an operator told "unknown"
     * would go looking for a spelling mistake.
     */
    public function test_it_refuses_to_change_the_inventory_accounting_method(): void
    {
        foreach ([false, true, 'mungkin', null] as $value) {
            $response = $this->putSettings(['accounting.perpetual_inventory' => $value]);

            $response->assertStatus(422);
            $this->assertSame(
                ['Parameter accounting.perpetual_inventory ditetapkan saat instalasi di config/erp.php '
                    .'dan tidak dapat diubah dari layar ini; mengubahnya membutuhkan deploy.'],
                $response->json('errors')['settings.accounting.perpetual_inventory'],
            );
        }

        // Nothing stored, and the method still in force is the shipped one.
        $this->assertDatabaseCount('core_settings', 0);
        $this->assertTrue((bool) app(SettingService::class)->get('accounting.perpetual_inventory'));
    }

    /**
     * And it is not on the screen to begin with, so no client of this API can
     * discover it from the payload it is handed.
     */
    public function test_the_settings_screen_does_not_offer_the_inventory_accounting_method(): void
    {
        $keys = collect($this->getJson('/api/core/settings')->assertOk()->json('data.groups'))
            ->flatMap(fn (array $group): array => $group['settings'])
            ->pluck('key');

        $this->assertNotContains('accounting.perpetual_inventory', $keys);
        $this->assertContains('accounting.opening_balance_account', $keys);
    }

    public function test_it_rejects_an_empty_payload(): void
    {
        $response = $this->putSettings([]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('settings', (array) $response->json('errors'));
        $this->assertDatabaseCount('core_settings', 0);
    }

    public function test_it_rejects_a_body_without_a_settings_object(): void
    {
        $response = $this->putJson('/api/core/settings', []);

        $response->assertStatus(422);
        $this->assertArrayHasKey('settings', (array) $response->json('errors'));
        $this->assertDatabaseCount('core_settings', 0);
    }

    public function test_one_bad_key_rejects_the_whole_batch(): void
    {
        $response = $this->putSettings([
            'tax.ppn_rate' => 12,          // valid on its own
            'payroll.overtime.divisor' => 9999, // out of range
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('settings.payroll.overtime.divisor', (array) $response->json('errors'));

        // Nothing was applied, not even the valid half.
        $this->assertDatabaseCount('core_settings', 0);
        $this->assertSame(11.0, (float) app(SettingService::class)->get('tax.ppn_rate'));
    }

    public function test_a_rejected_batch_leaves_an_existing_override_alone(): void
    {
        $this->setSetting('tax.ppn_rate', 12);

        $this->putSettings([
            'tax.ppn_rate' => 5,
            'documents.PO' => 'PO/{Y}', // no sequence token
        ])->assertStatus(422);

        $this->assertSame(12.0, (float) app(SettingService::class)->get('tax.ppn_rate'));
        $this->assertDatabaseCount('core_settings', 1);
    }
}
