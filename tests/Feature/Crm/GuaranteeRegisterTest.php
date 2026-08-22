<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Crm\Enums\GuaranteeStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Guarantee;
use Modules\Crm\Models\Quotation;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Register jaminan & asuransi.
 *
 * The paper trail this register replaces is free text: termin 1 of
 * CTR/2026/I/0001 conditions its DP 20% (Rp 9,7 miliar of Rp 48,5 miliar) on
 * "penyerahan jaminan uang muka", and the approval note confirms "jaminan uang
 * muka sudah diterima" — a bond that provably exists but whose number, issuer
 * and expiry live nowhere a query can reach. What matters here: identity is the
 * bank's (issuer, number), every row is anchored to a contract or a quotation,
 * and 'expired' is derived from end_date so a stale status cannot silence the
 * deadline watcher.
 */
class GuaranteeRegisterTest extends ErpTestCase
{
    private const TODAY = '2026-08-01';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY.' 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------- fixtures

    private function customer(): Customer
    {
        return Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'status' => 'active',
        ]);
    }

    private function contract(): Contract
    {
        return Contract::query()->create([
            'customer_id' => $this->customer()->id,
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)',
            'scope_type' => 'construction',
            'value' => 48_500_000_000,
            'status' => 'approved',
        ]);
    }

    private function quotation(): Quotation
    {
        return Quotation::query()->create([
            'customer_id' => $this->customer()->id,
            'title' => 'Penawaran Upgrade CCTV Gudang',
            'scope_type' => 'system_integration',
            'valid_until' => '2026-08-31',
            'status' => 'approved',
        ]);
    }

    /** The Rp 9,7 miliar advance-payment bond the free text proves exists. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'guarantee_type' => 'advance_payment_bond',
            'number' => 'BG/2026/0117',
            'issuer' => 'Bank Artha Nusantara',
            'value' => 9_700_000_000,
            'start_date' => '2026-02-01',
            'end_date' => '2027-02-01',
        ], $overrides);
    }

    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pengguna',
            'email' => str()->random(8).'@nusantara.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    // ------------------------------------------------------------- recording

    public function test_a_guarantee_is_recorded_against_its_contract(): void
    {
        $contract = $this->contract();

        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/guarantees', $this->payload(['contract_id' => $contract->id]))
            ->assertCreated()
            ->assertJsonPath('data.number', 'BG/2026/0117')
            ->assertJsonPath('data.issuer', 'Bank Artha Nusantara')
            ->assertJsonPath('data.guarantee_type_label', 'Jaminan Uang Muka')
            ->assertJsonPath('data.contract.code', $contract->code)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_expired', false);

        $this->assertDatabaseHas('crm_guarantees', [
            'number' => 'BG/2026/0117',
            'contract_id' => $contract->id,
            'status' => 'active',
        ]);
    }

    /** A bid bond exists before the contract it hopes to win does. */
    public function test_a_bid_bond_attaches_to_the_quotation_before_any_contract_exists(): void
    {
        $quotation = $this->quotation();

        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/guarantees', $this->payload([
                'guarantee_type' => 'bid_bond',
                'quotation_id' => $quotation->id,
                'value' => 1_019_100, // 3% of the Rp 33,97 juta tender
            ]))
            ->assertCreated()
            ->assertJsonPath('data.quotation.code', $quotation->code)
            ->assertJsonPath('data.contract_id', null);
    }

    /** Unanchored, the row is unfindable exactly when it matters. */
    public function test_a_guarantee_attached_to_nothing_is_refused(): void
    {
        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/guarantees', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contract_id', 'quotation_id']);
    }

    /**
     * Both anchors soft-delete, so a bare Rule::exists is satisfied by a
     * trashed row the relation then resolves to null — the bond would be
     * recorded and instantly unfindable (the QTN/2026/VII/0005 shape).
     */
    public function test_a_guarantee_anchored_to_a_deleted_quotation_is_refused(): void
    {
        $quotation = $this->quotation();
        $quotation->delete(); // soft

        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/guarantees', $this->payload([
                'guarantee_type' => 'bid_bond',
                'quotation_id' => $quotation->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('quotation_id');

        $this->assertDatabaseCount('crm_guarantees', 0);
    }

    public function test_an_end_date_before_the_start_date_is_refused(): void
    {
        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/guarantees', $this->payload([
                'contract_id' => $this->contract()->id,
                'start_date' => '2026-02-01',
                'end_date' => '2026-01-31',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    public function test_a_guarantee_of_zero_value_is_refused(): void
    {
        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/guarantees', $this->payload([
                'contract_id' => $this->contract()->id,
                'value' => 0,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }

    // -------------------------------------------------------------- identity

    public function test_the_same_number_from_the_same_issuer_is_refused(): void
    {
        $contract = $this->contract();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/api/crm/guarantees', $this->payload(['contract_id' => $contract->id]))
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/crm/guarantees', $this->payload(['contract_id' => $contract->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('number');
    }

    /** Plain numbers like "BG/2026/0117" collide across banks — only the pair is unique. */
    public function test_two_issuers_may_use_the_same_number(): void
    {
        $contract = $this->contract();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/api/crm/guarantees', $this->payload(['contract_id' => $contract->id]))
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/crm/guarantees', $this->payload([
                'contract_id' => $contract->id,
                'issuer' => 'Bank Nusantara Syariah',
            ]))
            ->assertCreated();
    }

    public function test_an_update_cannot_steal_another_rows_identity(): void
    {
        $contract = $this->contract();
        Guarantee::query()->create($this->payload(['contract_id' => $contract->id]));
        $other = Guarantee::query()->create($this->payload([
            'contract_id' => $contract->id,
            'number' => 'BG/2026/0200',
        ]));

        $this->actingAs($this->adminUser())
            ->putJson("/api/crm/guarantees/{$other->id}", ['number' => 'BG/2026/0117'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('number');
    }

    // -------------------------------------------------------------- updating

    /** Releasing the bond is one field — the operator must not re-key the row. */
    public function test_releasing_a_guarantee_needs_only_the_status(): void
    {
        $guarantee = Guarantee::query()->create($this->payload(['contract_id' => $this->contract()->id]));

        $this->actingAs($this->adminUser())
            ->putJson("/api/crm/guarantees/{$guarantee->id}", ['status' => 'released'])
            ->assertOk()
            ->assertJsonPath('data.status_label', 'Dikembalikan');

        $this->assertSame(GuaranteeStatus::Released, $guarantee->refresh()->status);
    }

    public function test_an_update_cannot_detach_a_guarantee_from_both_anchors(): void
    {
        $guarantee = Guarantee::query()->create($this->payload(['contract_id' => $this->contract()->id]));

        $this->actingAs($this->adminUser())
            ->putJson("/api/crm/guarantees/{$guarantee->id}", [
                'contract_id' => null,
                'quotation_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contract_id');

        $this->assertNotNull($guarantee->refresh()->contract_id);
    }

    /** Same soft-delete blindness on update: re-anchoring must check deleted_at. */
    public function test_an_update_cannot_re_anchor_to_a_deleted_contract(): void
    {
        $contract = $this->contract();
        $guarantee = Guarantee::query()->create($this->payload(['contract_id' => $contract->id]));
        $trashed = $this->contract();
        $trashed->delete(); // soft

        $this->actingAs($this->adminUser())
            ->putJson("/api/crm/guarantees/{$guarantee->id}", ['contract_id' => $trashed->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contract_id');

        $this->assertSame($contract->id, $guarantee->refresh()->contract_id);
    }

    public function test_an_update_cannot_reverse_the_dates(): void
    {
        $guarantee = Guarantee::query()->create($this->payload(['contract_id' => $this->contract()->id]));

        // Only end_date moves; the rule must still see the stored start_date.
        $this->actingAs($this->adminUser())
            ->putJson("/api/crm/guarantees/{$guarantee->id}", ['end_date' => '2026-01-15'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    // ----------------------------------------------------- expiry is derived

    /**
     * THE POINT OF THE REGISTER. Nobody edited this row since it lapsed, and it
     * still reports itself expired — because 'expired' is computed from
     * end_date, never stored where it could go stale.
     */
    public function test_expiry_is_derived_from_the_end_date_never_stored(): void
    {
        $guarantee = Guarantee::query()->create($this->payload([
            'contract_id' => $this->contract()->id,
            'start_date' => '2026-01-05',
            'end_date' => '2026-06-30', // 32 days before TODAY
        ]));

        $this->actingAs($this->adminUser())
            ->getJson("/api/crm/guarantees/{$guarantee->id}")
            ->assertOk()
            ->assertJsonPath('data.is_expired', true)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('crm_guarantees', [
            'id' => $guarantee->id,
            'status' => 'active', // the DB never learns the word 'expired'
        ]);
    }

    /** A guarantee handed back is finished, not lapsed — released beats expired. */
    public function test_a_released_guarantee_past_its_date_is_not_expired(): void
    {
        $guarantee = Guarantee::query()->create($this->payload([
            'contract_id' => $this->contract()->id,
            'end_date' => '2026-06-30',
            'status' => 'released',
        ]));

        $this->assertFalse($guarantee->isExpired());
    }

    // -------------------------------------------------------------- the list

    public function test_the_register_lists_the_soonest_expiry_first(): void
    {
        $contract = $this->contract();
        $later = Guarantee::query()->create($this->payload(['contract_id' => $contract->id, 'end_date' => '2027-02-01']));
        $soonest = Guarantee::query()->create($this->payload([
            'contract_id' => $contract->id, 'number' => 'BG/2026/0200', 'end_date' => '2026-09-15',
        ]));
        $middle = Guarantee::query()->create($this->payload([
            'contract_id' => $contract->id, 'number' => 'BG/2026/0300', 'end_date' => '2026-12-18',
        ]));

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/crm/guarantees')
            ->assertOk();

        $this->assertSame(
            [$soonest->id, $middle->id, $later->id],
            array_column($response->json('data'), 'id'),
        );
    }

    public function test_the_list_filters_by_status_and_type(): void
    {
        $contract = $this->contract();
        $active = Guarantee::query()->create($this->payload(['contract_id' => $contract->id]));
        Guarantee::query()->create($this->payload([
            'contract_id' => $contract->id, 'number' => 'BG/2026/0200', 'status' => 'released',
        ]));

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/crm/guarantees?status=active&guarantee_type=advance_payment_bond')
            ->assertOk();

        $this->assertSame([$active->id], array_column($response->json('data'), 'id'));
    }

    // -------------------------------------------------------------- deleting

    /** The register forgets nothing — a wrong entry is withdrawn, not erased. */
    public function test_a_removed_guarantee_is_soft_deleted_not_erased(): void
    {
        $guarantee = Guarantee::query()->create($this->payload(['contract_id' => $this->contract()->id]));

        $this->actingAs($this->adminUser())
            ->deleteJson("/api/crm/guarantees/{$guarantee->id}")
            ->assertOk();

        $this->assertSoftDeleted('crm_guarantees', ['id' => $guarantee->id]);
    }

    /**
     * The FK's restrictOnDelete only stops HARD deletes; quotation deletion is
     * soft, so the service guard is what keeps a live bid bond's anchor alive.
     * The refusal names the bond so the operator knows what to release first.
     */
    public function test_deleting_a_quotation_with_an_active_bid_bond_is_refused(): void
    {
        $quotation = Quotation::query()->create([
            'customer_id' => $this->customer()->id,
            'title' => 'Penawaran Upgrade CCTV Gudang',
            'scope_type' => 'system_integration',
            'valid_until' => '2026-08-31',
            'status' => 'draft', // editable — only the guarantee guard can refuse
        ]);
        Guarantee::query()->create($this->payload([
            'guarantee_type' => 'bid_bond',
            'quotation_id' => $quotation->id,
        ]));

        $response = $this->actingAs($this->adminUser())
            ->deleteJson("/api/crm/quotations/{$quotation->id}")
            ->assertStatus(422);

        $this->assertStringContainsString('BG/2026/0117', (string) $response->json('message'));
        $this->assertNull($quotation->refresh()->deleted_at);
    }

    public function test_deleting_a_contract_with_an_active_guarantee_is_refused(): void
    {
        $contract = Contract::query()->create([
            'customer_id' => $this->customer()->id,
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)',
            'scope_type' => 'construction',
            'value' => 48_500_000_000,
            'status' => 'draft', // editable — only the guarantee guard can refuse
        ]);
        Guarantee::query()->create($this->payload(['contract_id' => $contract->id]));

        $response = $this->actingAs($this->adminUser())
            ->deleteJson("/api/crm/contracts/{$contract->id}")
            ->assertStatus(422);

        $this->assertStringContainsString('BG/2026/0117', (string) $response->json('message'));
        $this->assertNull($contract->refresh()->deleted_at);
    }

    /** A released bond is finished — it must not block the delete. */
    public function test_a_quotation_whose_guarantee_was_released_can_be_deleted(): void
    {
        $quotation = Quotation::query()->create([
            'customer_id' => $this->customer()->id,
            'title' => 'Penawaran Upgrade CCTV Gudang',
            'scope_type' => 'system_integration',
            'valid_until' => '2026-08-31',
            'status' => 'draft',
        ]);
        Guarantee::query()->create($this->payload([
            'guarantee_type' => 'bid_bond',
            'quotation_id' => $quotation->id,
            'status' => 'released',
        ]));

        $this->actingAs($this->adminUser())
            ->deleteJson("/api/crm/quotations/{$quotation->id}")
            ->assertOk();

        $this->assertSoftDeleted('crm_quotations', ['id' => $quotation->id]);
    }

    // ------------------------------------------------------------ permissions

    public function test_a_viewer_can_read_the_register(): void
    {
        Guarantee::query()->create($this->payload(['contract_id' => $this->contract()->id]));

        $this->actingAs($this->userWith(['crm.view']))
            ->getJson('/api/crm/guarantees')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_recording_a_guarantee_needs_the_create_permission(): void
    {
        $contract = $this->contract();

        $this->actingAs($this->userWith(['crm.view']))
            ->postJson('/api/crm/guarantees', $this->payload(['contract_id' => $contract->id]))
            ->assertForbidden();
    }

    public function test_removing_a_guarantee_needs_the_delete_permission(): void
    {
        $guarantee = Guarantee::query()->create($this->payload(['contract_id' => $this->contract()->id]));

        $this->actingAs($this->userWith(['crm.view', 'crm.update']))
            ->deleteJson("/api/crm/guarantees/{$guarantee->id}")
            ->assertForbidden();

        $this->assertNull($guarantee->refresh()->deleted_at);
    }
}
