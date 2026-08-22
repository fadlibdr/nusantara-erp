<?php

namespace Tests\Feature\Core;

use Modules\Core\Services\SettingService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * An account setting may be MAPPED, but not MOVED out from under a balance.
 *
 * The journal engine reads its account codes from settings so an installation
 * can map them onto its own chart. But repointing one while the account it names
 * still carries a balance strands that balance: the engine stops posting there,
 * so nothing will ever relieve it, and only a hand-written journal voucher can.
 *
 * That is the same hazard accounting.perpetual_inventory was withdrawn from the
 * screen for. Rather than withdraw the codes too — an installation genuinely has
 * to map them — the change is allowed only while the account is empty, which is
 * exactly the install-time window mapping belongs in.
 */
class AccountRepointingGuardTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
    }

    public function test_an_account_carrying_a_balance_cannot_be_repointed(): void
    {
        // Put a balance on 1-1400 Persediaan Material: Dr 1-1400 / Cr 2-1100.
        $this->journals()->autoPost(
            'test', 1,
            [
                ['account_code' => '1-1400', 'debit' => 5_000_000],
                ['account_code' => '2-1100', 'credit' => 5_000_000],
            ],
            '2026-03-10', 'Saldo persediaan untuk pengujian', $this->financeUser()->id,
        );

        $response = $this->actingAs($this->adminUser(), 'sanctum')
            ->putJson('/api/core/settings', [
                'settings' => ['accounting.inventory_account' => '1-1100'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('settings.accounting.inventory_account');

        $this->assertStringContainsString(
            'masih memiliki saldo',
            $response->json('errors.settings\\.accounting\\.inventory_account.0')
                ?? $response->json('message'),
        );

        // And nothing was stored.
        $this->assertSame('1-1400', app(SettingService::class)->get('accounting.inventory_account'));
        $this->assertDatabaseMissing('core_settings', ['key' => 'accounting.inventory_account']);
    }

    public function test_an_empty_account_can_still_be_mapped(): void
    {
        // 6-4500 Selisih Harga Pembelian has never been posted to.
        $this->actingAs($this->adminUser(), 'sanctum')
            ->putJson('/api/core/settings', [
                'settings' => ['accounting.purchase_variance_account' => '6-4300'],
            ])
            ->assertOk();

        $this->assertSame('6-4300', app(SettingService::class)->get('accounting.purchase_variance_account'));
    }

    public function test_resetting_to_the_shipped_default_is_also_a_repoint(): void
    {
        $settings = app(SettingService::class);
        $settings->set('accounting.inventory_account', '1-1100');

        // Now give 1-1100 a balance, so resetting back to 1-1400 would strand it.
        $this->journals()->autoPost(
            'test', 2,
            [
                ['account_code' => '1-1100', 'debit' => 2_500_000],
                ['account_code' => '2-1100', 'credit' => 2_500_000],
            ],
            '2026-03-11', 'Saldo kas untuk pengujian', $this->financeUser()->id,
        );

        $this->actingAs($this->adminUser(), 'sanctum')
            ->putJson('/api/core/settings', [
                'settings' => ['accounting.inventory_account' => null],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings.accounting.inventory_account');

        $this->assertSame('1-1100', $settings->get('accounting.inventory_account'));
    }

    public function test_submitting_the_same_code_again_is_not_treated_as_a_change(): void
    {
        $this->journals()->autoPost(
            'test', 3,
            [
                ['account_code' => '1-1400', 'debit' => 1_000_000],
                ['account_code' => '2-1100', 'credit' => 1_000_000],
            ],
            '2026-03-12', 'Saldo persediaan untuk pengujian', $this->financeUser()->id,
        );

        // A settings screen posts every field it rendered; an unchanged one must
        // not trip the guard.
        $this->actingAs($this->adminUser(), 'sanctum')
            ->putJson('/api/core/settings', [
                'settings' => ['accounting.inventory_account' => '1-1400'],
            ])
            ->assertOk();
    }
}
