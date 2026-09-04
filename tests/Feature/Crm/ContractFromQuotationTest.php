<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Services\ContractService;
use Modules\Crm\Services\QuotationService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * T3.6 — kontrak dari penawaran yang menang (ANALISIS-PROSES §1.1 / §3 A1).
 *
 * Production, 4 Sep 2026: QTN/2026/VIII/0008 Rp 2,04 M → CTR/2026/VIII/0004
 * Rp 1,84 M, typed by hand, no quotation_id, no word on why the two numbers
 * differ; and QTN/2026/VII/0004 won 22 Aug → CTR/2026/VIII/0005 (the shell
 * Tandai Menang mints) still a draft 13 days later. This pins the endpoint
 * that copies the won quotation into its contract, the rule that a contract
 * worth something else than its quotation must say why (both amounts named),
 * and that the mark-won shell is completed rather than duplicated.
 */
class ContractFromQuotationTest extends ErpTestCase
{
    private const QUOTED_DPP = 2_040_000_000.0;

    private const SIGNED_VALUE = 1_840_000_000.0;

    private function userWith(string ...$permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pemegang '.implode(' ', $permissions),
            'email' => substr(md5(implode('|', $permissions)), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function customer(): Customer
    {
        return Customer::query()->create([
            'name' => 'PT Mitra Sarana Abadi',
            'is_pkp' => true,
            'status' => 'active',
        ]);
    }

    /** An approved quotation worth Rp 2,04 M (DPP) — the production shape of QTN/2026/VIII/0008. */
    private function approvedQuotation(): Quotation
    {
        $quotation = app(QuotationService::class)->create([
            'customer_id' => $this->customer()->id,
            'title' => 'Instalasi CCTV & Akses Kontrol Gedung Parkir',
            'scope_type' => 'system_integration',
            'items' => [
                ['description' => 'Instalasi CCTV 120 titik', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 1_540_000_000],
                ['description' => 'Akses kontrol 24 pintu', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 500_000_000],
            ],
        ]);

        $quotation->forceFill(['status' => DocumentStatus::Approved])->save();

        return $quotation->refresh();
    }

    /** Won without the mark-won shell — the legacy shape (contract deleted, or won before markWon existed). */
    private function wonQuotation(): Quotation
    {
        $quotation = $this->approvedQuotation();
        $quotation->forceFill(['won_at' => now()])->save();

        return $quotation->refresh();
    }

    private function schedule(): array
    {
        return [
            ['name' => 'DP 30%', 'percent' => 30, 'billing_condition' => 'Setelah tanda tangan kontrak.'],
            ['name' => 'BAST 70%', 'percent' => 70, 'billing_condition' => 'Serah terima pekerjaan.'],
        ];
    }

    private function createContract(User $user, Quotation $quotation, array $overrides = [])
    {
        return $this->actingAs($user)->postJson(
            "api/crm/quotations/{$quotation->id}/create-contract",
            array_merge(['termins' => $this->schedule(), 'sign_date' => '2026-09-04'], $overrides),
        );
    }

    // ------------------------------------------------------------ the copy

    public function test_a_won_quotation_becomes_a_contract_carrying_its_customer_value_and_link(): void
    {
        $quotation = $this->wonQuotation();
        $this->assertEqualsWithDelta(self::QUOTED_DPP, (float) $quotation->dpp, 0.01, 'fixture: the quotation is worth Rp 2,04 M DPP');

        $response = $this->createContract($this->userWith('crm.create'), $quotation)->assertCreated();

        $this->assertStringStartsWith('CTR/', $response->json('data.code'));
        $this->assertSame($quotation->id, $response->json('data.quotation_id'));
        $this->assertSame($quotation->code, $response->json('data.quotation_code'));
        $this->assertSame($quotation->customer_id, $response->json('data.customer_id'));
        $this->assertSame($quotation->title, $response->json('data.title'));
        $this->assertSame('system_integration', $response->json('data.scope_type'));
        $this->assertEqualsWithDelta(self::QUOTED_DPP, (float) $response->json('data.value'), 0.01);
        $this->assertEqualsWithDelta((float) $quotation->ppn_rate, (float) $response->json('data.ppn_rate'), 0.0001);
        $this->assertEqualsWithDelta((float) $quotation->total, (float) $response->json('data.total_with_ppn'), 0.01, 'same DPP and rate → same total as the offer');
        $this->assertNull($response->json('data.value_change_reason'));
        $this->assertSame('2026-09-04', $response->json('data.sign_date'));
        $this->assertSame('draft', $response->json('data.status'));

        $termins = $response->json('data.termins');
        $this->assertCount(2, $termins);
        $this->assertEqualsWithDelta(612_000_000, (float) $termins[0]['amount'], 0.01);
        $this->assertEqualsWithDelta(1_428_000_000, (float) $termins[1]['amount'], 0.01);

        // The detail the SPA renders "Dari penawaran QTN/…" from.
        $this->actingAs($this->userWith('crm.view'))
            ->getJson("api/crm/contracts/{$response->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.quotation_code', $quotation->code);
    }

    public function test_the_quotation_shows_its_contract_once_one_exists(): void
    {
        $quotation = $this->wonQuotation();
        $viewer = $this->userWith('crm.view');

        $before = $this->actingAs($viewer)->getJson("api/crm/quotations/{$quotation->id}")->assertOk();
        $this->assertNull($before->json('data.contract_code'));
        $this->assertFalse($before->json('data.contract_needs_schedule'));

        $created = $this->createContract($this->userWith('crm.create'), $quotation)->assertCreated();

        $after = $this->actingAs($viewer)->getJson("api/crm/quotations/{$quotation->id}")->assertOk();
        $this->assertSame($created->json('data.code'), $after->json('data.contract_code'));
        $this->assertFalse($after->json('data.contract_needs_schedule'));
    }

    // ---------------------------------------------------- the value rule

    public function test_a_different_value_without_a_reason_is_refused_naming_both_amounts(): void
    {
        $quotation = $this->wonQuotation();

        $response = $this->createContract($this->userWith('crm.create'), $quotation, ['value' => self::SIGNED_VALUE])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value_change_reason']);

        $message = $response->json('errors.value_change_reason.0');
        $this->assertStringContainsString('1.840.000.000', $message, 'the contract value');
        $this->assertStringContainsString('2.040.000.000', $message, 'the quotation value');
        $this->assertStringContainsString($quotation->code, $message);
        $this->assertSame(0, Contract::query()->count(), 'nothing is minted on refusal');
    }

    public function test_a_different_value_with_a_reason_is_stored_and_shown(): void
    {
        $quotation = $this->wonQuotation();

        $response = $this->createContract($this->userWith('crm.create'), $quotation, [
            'value' => self::SIGNED_VALUE,
            'value_change_reason' => 'Negosiasi akhir: lingkup akses kontrol dikurangi 8 pintu.',
        ])->assertCreated();

        $this->assertEqualsWithDelta(self::SIGNED_VALUE, (float) $response->json('data.value'), 0.01);
        $this->assertSame('Negosiasi akhir: lingkup akses kontrol dikurangi 8 pintu.', $response->json('data.value_change_reason'));

        $this->actingAs($this->userWith('crm.view'))
            ->getJson("api/crm/contracts/{$response->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.value_change_reason', 'Negosiasi akhir: lingkup akses kontrol dikurangi 8 pintu.');
    }

    public function test_a_reason_typed_for_an_unchanged_value_is_not_kept(): void
    {
        $quotation = $this->wonQuotation();

        $response = $this->createContract($this->userWith('crm.create'), $quotation, [
            'value_change_reason' => 'Tidak ada yang berubah, sebenarnya.',
        ])->assertCreated();

        $this->assertNull($response->json('data.value_change_reason'), 'a reason explains a difference; without one there is nothing to record');
    }

    public function test_the_generic_contract_store_applies_the_same_rule_to_a_linked_contract(): void
    {
        $quotation = $this->wonQuotation();
        $maker = $this->userWith('crm.create');
        $payload = [
            'customer_id' => $quotation->customer_id,
            'quotation_id' => $quotation->id,
            'title' => $quotation->title,
            'scope_type' => 'system_integration',
            'value' => self::SIGNED_VALUE,
            'termins' => $this->schedule(),
        ];

        $this->actingAs($maker)->postJson('api/crm/contracts', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value_change_reason']);

        $this->actingAs($maker)->postJson('api/crm/contracts', $payload + ['value_change_reason' => 'Diskon volume 10%.'])
            ->assertCreated()
            ->assertJsonPath('data.value_change_reason', 'Diskon volume 10%.');
    }

    public function test_editing_the_value_away_from_the_quotation_needs_a_reason_and_returning_to_it_clears_the_reason(): void
    {
        $quotation = $this->wonQuotation();
        $editor = $this->userWith('crm.create', 'crm.update');
        $id = $this->createContract($editor, $quotation)->assertCreated()->json('data.id');

        $this->actingAs($editor)->putJson("api/crm/contracts/{$id}", ['value' => self::SIGNED_VALUE])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value_change_reason']);
        $this->assertEqualsWithDelta(self::QUOTED_DPP, (float) Contract::query()->findOrFail($id)->value, 0.01, 'the refused edit leaves the value alone');

        $this->actingAs($editor)->putJson("api/crm/contracts/{$id}", [
            'value' => self::SIGNED_VALUE,
            'value_change_reason' => 'Addendum negosiasi 3 Sep 2026.',
        ])->assertOk()->assertJsonPath('data.value_change_reason', 'Addendum negosiasi 3 Sep 2026.');

        // A line-only edit keeps the stored reason; a value back at the offer clears it.
        $this->actingAs($editor)->putJson("api/crm/contracts/{$id}", ['termins' => $this->schedule()])
            ->assertOk()->assertJsonPath('data.value_change_reason', 'Addendum negosiasi 3 Sep 2026.');
        $this->actingAs($editor)->putJson("api/crm/contracts/{$id}", ['value' => self::QUOTED_DPP])
            ->assertOk()->assertJsonPath('data.value_change_reason', null);
    }

    // ------------------------------------------------ what is refused

    public function test_a_quotation_not_yet_won_is_refused(): void
    {
        $quotation = $this->approvedQuotation();

        $response = $this->createContract($this->userWith('crm.create'), $quotation)->assertStatus(422);

        $this->assertStringContainsString($quotation->code, (string) $response->json('message'));
        $this->assertStringContainsString('belum ditandai menang', (string) $response->json('message'));
        $this->assertSame(0, Contract::query()->count());
    }

    public function test_a_quotation_whose_contract_is_already_complete_is_refused_naming_it(): void
    {
        $quotation = $this->wonQuotation();
        $existing = app(ContractService::class)->create([
            'customer_id' => $quotation->customer_id,
            'quotation_id' => $quotation->id,
            'title' => $quotation->title,
            'scope_type' => 'system_integration',
            'value' => self::QUOTED_DPP,
            'termins' => $this->schedule(),
        ]);

        $response = $this->createContract($this->userWith('crm.create'), $quotation)->assertStatus(422);

        $this->assertStringContainsString($existing->code, (string) $response->json('message'));
        $this->assertSame(1, Contract::query()->count());
    }

    public function test_the_endpoint_needs_crm_create(): void
    {
        $this->createContract($this->userWith('crm.view'), $this->wonQuotation())->assertForbidden();
    }

    // --------------------------------------------- the mark-won shell

    public function test_the_shell_minted_by_mark_won_is_completed_not_duplicated(): void
    {
        $quotation = $this->approvedQuotation();
        $shell = app(QuotationService::class)->markWon($quotation);
        $this->assertSame(0, $shell->termins()->count(), 'fixture: Tandai Menang mints a contract without a schedule');

        $viewer = $this->userWith('crm.view');
        $before = $this->actingAs($viewer)->getJson("api/crm/quotations/{$quotation->id}")->assertOk();
        $this->assertSame($shell->code, $before->json('data.contract_code'));
        $this->assertTrue($before->json('data.contract_needs_schedule'));

        $response = $this->createContract($this->userWith('crm.create'), $quotation->refresh())->assertOk();

        $this->assertSame($shell->id, $response->json('data.id'), 'the CTR number Tandai Menang minted is the contract');
        $this->assertSame($shell->code, $response->json('data.code'));
        $this->assertCount(2, $response->json('data.termins'));
        $this->assertSame('2026-09-04', $response->json('data.sign_date'));
        $this->assertSame(1, Contract::query()->count(), 'no second contract for the same quotation');

        $after = $this->actingAs($viewer)->getJson("api/crm/quotations/{$quotation->id}")->assertOk();
        $this->assertFalse($after->json('data.contract_needs_schedule'));
    }

    public function test_completing_the_shell_with_another_value_needs_the_same_reason(): void
    {
        $quotation = $this->approvedQuotation();
        $shell = app(QuotationService::class)->markWon($quotation);
        $maker = $this->userWith('crm.create');

        $this->createContract($maker, $quotation->refresh(), ['value' => self::SIGNED_VALUE])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value_change_reason']);
        $this->assertEqualsWithDelta(self::QUOTED_DPP, (float) $shell->refresh()->value, 0.01);

        $this->createContract($maker, $quotation, ['value' => self::SIGNED_VALUE, 'value_change_reason' => 'Nilai final hasil klarifikasi.'])
            ->assertOk()
            ->assertJsonPath('data.id', $shell->id)
            ->assertJsonPath('data.value_change_reason', 'Nilai final hasil klarifikasi.');
    }
}
