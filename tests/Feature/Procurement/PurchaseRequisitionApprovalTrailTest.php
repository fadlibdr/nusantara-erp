<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseRequisition;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * GET procurement/purchase-requisitions/{id} carries the approval trail (T3.3).
 *
 * Measured 4 Sep 2026 (HASIL-UJI §6 P-4, ANALISIS-PROSES §3 C2): only 5 of 28
 * show() methods loaded `approvals`, so a PR detail rendered Informasi · Item ·
 * Lampiran · Metadata and never the "Riwayat Persetujuan" card, and the status
 * strip fell back to "Diajukan · menunggu persetujuan." with no name and no
 * date — although both rows sat in core_approvals. The name and the date ARE
 * maker-checker; they have to be visible where people look for them, not in
 * the database.
 *
 * Pins the PaymentResource shape (id, action, note, created_at ISO-8601, user
 * {id, name} or null) so the SPA's one timeline renderer keeps working for
 * every document alike.
 */
class PurchaseRequisitionApprovalTrailTest extends ErpTestCase
{
    public function test_show_returns_the_trail_with_actor_name_note_and_date(): void
    {
        $submitter = $this->userWith('prc.create');
        $approver = $this->userWith('prc.approve');
        $reader = $this->userWith('prc.view');

        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-12-31',
            'status' => 'draft',
            'purpose' => 'Kabel UTP Cat6 untuk lantai 3',
            'requested_by' => $submitter->id,
        ]);

        Carbon::setTestNow('2026-09-01 10:00:00');
        $pr->submit($submitter);
        Carbon::setTestNow('2026-09-04 09:15:00');
        $pr->approve($approver, 'Harga sesuai RAB.');

        $response = $this->actingAs($reader)
            ->getJson("/api/procurement/purchase-requisitions/{$pr->id}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonCount(2, 'data.approvals')
            ->assertJsonPath('data.approvals.0.action', 'submitted')
            ->assertJsonPath('data.approvals.0.user.id', $submitter->id)
            ->assertJsonPath('data.approvals.0.user.name', $submitter->name)
            ->assertJsonPath('data.approvals.0.note', null)
            ->assertJsonPath('data.approvals.0.created_at', '2026-09-01T10:00:00+07:00')
            ->assertJsonPath('data.approvals.1.action', 'approved')
            ->assertJsonPath('data.approvals.1.user.name', $approver->name)
            ->assertJsonPath('data.approvals.1.note', 'Harga sesuai RAB.')
            ->assertJsonPath('data.approvals.1.created_at', '2026-09-04T09:15:00+07:00');

        // Exactly the PaymentResource keys — nothing the SPA does not read.
        $this->assertSame(
            ['id', 'action', 'note', 'created_at', 'user'],
            array_keys($response->json('data.approvals.0')),
        );
    }

    /**
     * A row whose actor is gone (user_id null — the seeded "submit as nobody"
     * path SegregationOfDuties documents) is still a row: user null, not a
     * crash and not a dropped entry. The SPA prints "Sistem" for it.
     */
    public function test_a_row_without_an_actor_is_returned_with_user_null(): void
    {
        $reader = $this->userWith('prc.view');

        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-12-31',
            'status' => 'draft',
            'purpose' => 'Diajukan tanpa aktor',
            'requested_by' => $reader->id,
        ]);
        $pr->submit(null);

        $this->actingAs($reader)
            ->getJson("/api/procurement/purchase-requisitions/{$pr->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.approvals')
            ->assertJsonPath('data.approvals.0.action', 'submitted')
            ->assertJsonPath('data.approvals.0.user', null);
    }

    /**
     * A draft that was never submitted answers `approvals: []`, not a missing
     * key: the detail page draws the card only when the key is present, and
     * an empty card that says "Belum ada riwayat persetujuan." is the truth
     * for a draft — a missing card is not.
     */
    public function test_a_draft_without_a_trail_returns_an_empty_list(): void
    {
        $reader = $this->userWith('prc.view');

        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-12-31',
            'status' => 'draft',
            'purpose' => 'Masih draf',
            'requested_by' => $reader->id,
        ]);

        $this->actingAs($reader)
            ->getJson("/api/procurement/purchase-requisitions/{$pr->id}")
            ->assertOk()
            ->assertJsonPath('data.approvals', []);
    }

    /**
     * The list stays as it was: whenLoaded() keeps the trail off index(), so
     * the change adds no query per row to the PR list.
     */
    public function test_the_index_does_not_carry_the_trail(): void
    {
        $reader = $this->userWith('prc.view');

        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-12-31',
            'status' => 'draft',
            'purpose' => 'Daftar tanpa jejak',
            'requested_by' => $reader->id,
        ]);
        $pr->submit($reader);

        $this->actingAs($reader)
            ->getJson('/api/procurement/purchase-requisitions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $pr->id)
            ->assertJsonMissingPath('data.0.approvals');
    }

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
}
