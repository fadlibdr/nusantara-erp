<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Vendor;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * The dates that drive the deadline watchers are required on the form (T3.5).
 *
 * Measured 4 Sep 2026 on production (ANALISIS-PROSES §2-§3, D1): PO/2026/III/0002,
 * Rp 128 jt, approved 40 days earlier with 0 GRN — and the `po_expected` entry
 * of WatchedDeadlines never named it, because its expected_date is NULL and a
 * watcher is only as strong as the column it reads. The form had never asked
 * for the date. Same pattern for a PR without needed_date (`pr_needed`).
 *
 * PR needed_date was already required on both sides (PurchaseRequisitionStoreRequest
 * + schema.js); it is pinned here, not re-added. PO expected_date becomes
 * required on the store request and — because Ubah renders the same form —
 * cannot be blanked on update. The 422 sentence is what the SPA paints under
 * the field: the attribute name from lang/id/validation.php, "Perkiraan kirim".
 */
class RequiredWatchDatesTest extends ErpTestCase
{
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

    private function vendor(): Vendor
    {
        return Vendor::query()->create([
            'name' => 'PT Pemasok Baja Utama',
            'classification' => 'material',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function poPayload(array $overrides = []): array
    {
        return array_merge([
            'vendor_id' => $this->vendor()->id,
            'order_date' => '2026-08-08',
            'items' => [
                ['description' => 'Kabel NYY 4x10', 'qty' => 50, 'unit' => 'm', 'unit_price' => 125_000],
            ],
        ], $overrides);
    }

    // ------------------------------------------------------------------ PR

    public function test_a_pr_without_needed_date_is_refused_in_indonesian(): void
    {
        $this->actingAs($this->userWith('prc.create'))
            ->postJson('/api/procurement/purchase-requisitions', [
                'purpose' => 'Kabel UTP Cat6 untuk lantai 3',
                'items' => [['description' => 'Kabel UTP Cat6', 'qty' => 10, 'unit' => 'roll']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.needed_date.0', 'Dibutuhkan wajib diisi.');
    }

    // ------------------------------------------------------------------ PO

    public function test_a_po_without_expected_date_is_refused_with_the_sentence_the_form_paints(): void
    {
        $this->actingAs($this->userWith('prc.create'))
            ->postJson('/api/procurement/purchase-orders', $this->poPayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.expected_date.0', 'Perkiraan kirim wajib diisi.');

        $this->assertSame(0, PurchaseOrder::query()->count(), 'A refused PO must not be created.');
    }

    public function test_a_po_with_expected_date_is_created_and_the_date_is_stored(): void
    {
        $response = $this->actingAs($this->userWith('prc.create'))
            ->postJson('/api/procurement/purchase-orders', $this->poPayload(['expected_date' => '2026-08-22']))
            ->assertCreated()
            ->assertJsonPath('data.expected_date', '2026-08-22');

        $this->assertSame(
            '2026-08-22',
            PurchaseOrder::query()->findOrFail($response->json('data.id'))->expected_date?->toDateString(),
        );
    }

    /**
     * Ubah renders the same form with the same required mark; a draft edited
     * to a blank date would fall out of the watch again. A PUT that does not
     * carry the key (PoBoqLinkTest's line-only edit) is still fine — the
     * stored date stays.
     */
    public function test_an_ubah_cannot_blank_the_expected_date(): void
    {
        $user = $this->userWith('prc.create', 'prc.update');

        $poId = $this->actingAs($user)
            ->postJson('/api/procurement/purchase-orders', $this->poPayload(['expected_date' => '2026-08-22']))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($user)
            ->putJson("/api/procurement/purchase-orders/{$poId}", ['expected_date' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.expected_date.0', 'Perkiraan kirim wajib diisi.');

        $this->actingAs($user)
            ->putJson("/api/procurement/purchase-orders/{$poId}", ['notes' => 'Kirim ke gudang proyek.'])
            ->assertOk()
            ->assertJsonPath('data.expected_date', '2026-08-22');
    }

    /**
     * Why PurchaseOrderFromPrRequest keeps expected_date nullable: "Buat PO"
     * from an approved PR inherits the PR's needed_date (PoService::createFromPr),
     * and needed_date is itself required — so that PO is never date-less.
     */
    public function test_a_po_created_from_a_pr_inherits_the_pr_needed_date(): void
    {
        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-08-20',
            'status' => DocumentStatus::Approved,
        ]);
        $pr->items()->create([
            'line_no' => 1, 'description' => 'Semen PCC 50 kg', 'qty' => 100, 'unit' => 'zak', 'estimated_price' => 75_000,
        ]);

        $this->actingAs($this->userWith('prc.create'))
            ->postJson("/api/procurement/purchase-requisitions/{$pr->id}/create-po", ['vendor_id' => $this->vendor()->id])
            ->assertCreated()
            ->assertJsonPath('data.expected_date', '2026-08-20');
    }
}
