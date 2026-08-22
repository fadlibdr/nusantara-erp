<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * needs_director_approval must be a gate, not a caption.
 *
 * The flag was stamped on submit, shown as "Perlu persetujuan direktur" and
 * announced in the submit response — and nothing read it at approval time, so
 * on the live dataset PO/2026/II/0001 (Rp 232.545.000, above the Rp 100 juta
 * threshold) was approved by a single non-director login while the screen said
 * a director was required. The gate now lives in PoService::approve /
 * DirectorApproval and turns on prc.approve-director, which only direktur and
 * admin are seeded with.
 */
class PurchaseOrderDirectorApprovalTest extends ErpTestCase
{
    /** PO/2026/II/0001's own number: 2,3× the Rp 100.000.000 threshold. */
    private const ABOVE_THRESHOLD = 232545000.0;

    private const BELOW_THRESHOLD = 50000000.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userHolding(string $email, string ...$permissions): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Petugas '.$email,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function submittedPo(float $total, User $maker): PurchaseOrder
    {
        $vendor = Vendor::query()->firstOrCreate(
            ['name' => 'PT Semen Distribusi Utama'],
            [
                'classification' => 'material',
                'is_pkp' => false,
                'is_subcontractor' => false,
                'payment_term_days' => 30,
                'status' => 'active',
            ],
        );

        /** @var PurchaseOrder $po */
        $po = PurchaseOrder::query()->create([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => $total,
            'discount_amount' => 0,
            'dpp' => $total,
            'ppn_rate' => 0,
            'ppn_amount' => 0,
            'total' => $total,
            'status' => DocumentStatus::Draft,
        ]);

        return $po->submit($maker);
    }

    private function approveVia(PurchaseOrder $po)
    {
        return $this->postJson("/api/procurement/purchase-orders/{$po->id}/approve");
    }

    public function test_a_plain_approver_is_refused_an_above_threshold_po_and_told_who_can(): void
    {
        $po = $this->submittedPo(self::ABOVE_THRESHOLD, $this->userHolding('buyer@test.local', 'prc.update'));
        $this->assertTrue($po->needs_director_approval, 'submit must have stamped the flag');

        Sanctum::actingAs($this->userHolding('kabag-pengadaan@test.local', 'prc.approve'));

        $response = $this->approveVia($po)->assertStatus(422);

        // The refusal names the document, both numbers and the way out.
        $message = (string) $response->json('message');
        $this->assertStringContainsString($po->code, $message);
        $this->assertStringContainsString('Rp 232.545.000', $message);
        $this->assertStringContainsString('Rp 100.000.000', $message);
        $this->assertStringContainsString('prc.approve-director', $message);

        // Refused means untouched: still awaiting approval, no approved row.
        $this->assertSame(DocumentStatus::Submitted, $po->fresh()->status);
        $this->assertSame(0, $po->approvals()->where('action', 'approved')->count());
    }

    public function test_a_director_approves_the_same_po(): void
    {
        $po = $this->submittedPo(self::ABOVE_THRESHOLD, $this->userHolding('buyer@test.local', 'prc.update'));

        Sanctum::actingAs($this->userHolding(
            'direktur@test.local',
            'prc.approve',
            'prc.approve-director',
        ));

        $this->approveVia($po)->assertOk();

        $this->assertSame(DocumentStatus::Approved, $po->fresh()->status);
    }

    public function test_a_po_below_the_threshold_still_needs_no_director(): void
    {
        $po = $this->submittedPo(self::BELOW_THRESHOLD, $this->userHolding('buyer@test.local', 'prc.update'));
        $this->assertFalse($po->needs_director_approval);

        Sanctum::actingAs($this->userHolding('kabag-pengadaan@test.local', 'prc.approve'));

        $this->approveVia($po)->assertOk();

        $this->assertSame(DocumentStatus::Approved, $po->fresh()->status);
    }

    /**
     * The gates COMPOSE. Director level answers "is this approver senior
     * enough", maker-checker answers "is this approver somebody else" — a
     * director who submitted the PO passes the first and must still fail the
     * second, or the two-signature promise collapses back to one login.
     */
    public function test_a_director_who_submitted_the_po_is_still_refused_as_its_maker(): void
    {
        $director = $this->userHolding(
            'direktur@test.local',
            'prc.update',
            'prc.approve',
            'prc.approve-director',
        );

        $po = $this->submittedPo(self::ABOVE_THRESHOLD, $director);

        Sanctum::actingAs($director);

        $response = $this->approveVia($po)->assertStatus(422);

        $this->assertStringContainsString(
            'tidak boleh disetujui oleh pengajunya sendiri',
            (string) $response->json('message'),
        );
        $this->assertSame(DocumentStatus::Submitted, $po->fresh()->status);
    }
}
