<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\AwardDecision;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\Vendor;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P2 — Keputusan Pemenang (Award) dan kriteria #4.
 *
 * Dua mesin diuji di sini:
 *   1. AMBANG N-LEVEL — jumlah penyetuju BERBEDA yang diperlukan naik dengan
 *      nilai award, tingkat 2+ menuntut prc.approve-director, dan dokumen baru
 *      'approved' di tingkat terakhir.
 *   2. KRITERIA #4 — (a) PO dari RFQ tak bisa disetujui tanpa award disetujui;
 *      (b) award yang nilainya berbeda dari penawaran terakhir tak bisa diajukan
 *      tanpa BA Negosiasi (422 menyebut yang kurang).
 */
class AwardDecisionApprovalTest extends ErpTestCase
{
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
            'name' => 'Petugas '.$email, 'email' => $email,
            'password' => 'password', 'is_active' => true,
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function vendor(string $code = 'VND-A1', string $name = 'PT Pemenang'): Vendor
    {
        return Vendor::query()->create([
            'code' => $code, 'name' => $name, 'classification' => 'material',
            'is_pkp' => false, 'is_subcontractor' => false,
            'payment_term_days' => 30, 'status' => 'active',
        ]);
    }

    /** RFQ mandiri satu baris qty 1 tanpa tautan BOQ, satu vendor + harga. */
    private function rfqWithQuote(Vendor $vendor, float $price, User $staff): Rfq
    {
        $created = $this->actingAs($staff)->postJson('/api/procurement/rfqs', [
            'rfq_date' => '2026-08-08',
            'vendor_ids' => [$vendor->id],
            'items' => [['description' => 'Genset 100 kVA', 'qty' => 1, 'unit' => 'unit']],
        ])->assertStatus(201);

        $rfq = Rfq::query()->findOrFail((int) $created->json('data.id'));
        $itemId = $rfq->items()->value('id');

        $this->actingAs($staff)->postJson("/api/procurement/rfqs/{$rfq->id}/quotes", [
            'quotes' => [['rfq_item_id' => $itemId, 'vendor_id' => $vendor->id, 'unit_price' => $price]],
        ])->assertOk();

        return $rfq;
    }

    private function award(User $staff, Rfq $rfq, Vendor $vendor, float $rab, float $awarded, ?string $reason = null)
    {
        return $this->actingAs($staff)->postJson('/api/procurement/award-decisions', array_filter([
            'rfq_id' => $rfq->id, 'vendor_id' => $vendor->id,
            'rab_amount' => $rab, 'awarded_amount' => $awarded,
            'deviation_reason' => $reason,
        ], fn ($v) => $v !== null));
    }

    // ================================================= n-level ladder

    public function test_a_small_award_needs_one_approver(): void
    {
        $staff = $this->userHolding('staff@t', 'prc.create', 'prc.update', 'prc.view');
        $approver = $this->userHolding('appr@t', 'prc.approve');

        $vendor = $this->vendor();
        $rfq = $this->rfqWithQuote($vendor, 50_000_000, $staff);

        $id = (int) $this->award($staff, $rfq, $vendor, 50_000_000, 50_000_000)->assertStatus(201)->json('data.id');
        $this->actingAs($staff)->postJson("/api/procurement/award-decisions/{$id}/submit")->assertOk();

        $this->actingAs($approver)->postJson("/api/procurement/award-decisions/{$id}/approve")->assertOk();

        $this->assertSame(DocumentStatus::Approved, AwardDecision::query()->find($id)->status);
    }

    public function test_a_medium_award_needs_two_distinct_approvers_the_second_a_director(): void
    {
        $staff = $this->userHolding('staff@t', 'prc.create', 'prc.update', 'prc.view');
        $approver = $this->userHolding('appr@t', 'prc.approve');
        $director = $this->userHolding('dir@t', 'prc.approve', 'prc.approve-director');

        $vendor = $this->vendor();
        $rfq = $this->rfqWithQuote($vendor, 200_000_000, $staff);

        $id = (int) $this->award($staff, $rfq, $vendor, 200_000_000, 200_000_000)->assertStatus(201)->json('data.id');
        $this->actingAs($staff)->postJson("/api/procurement/award-decisions/{$id}/submit")->assertOk();

        // Level 1 by an ordinary approver: recorded, but NOT yet approved.
        $this->actingAs($approver)->postJson("/api/procurement/award-decisions/{$id}/approve")->assertOk();
        $this->assertSame(DocumentStatus::Submitted, AwardDecision::query()->find($id)->status);

        // Level 2 offered by a NON-director is refused.
        $plain = $this->userHolding('appr2@t', 'prc.approve');
        $this->actingAs($plain)->postJson("/api/procurement/award-decisions/{$id}/approve")->assertStatus(422);

        // Level 2 by a director completes it.
        $this->actingAs($director)->postJson("/api/procurement/award-decisions/{$id}/approve")->assertOk();
        $this->assertSame(DocumentStatus::Approved, AwardDecision::query()->find($id)->status);
    }

    public function test_the_same_person_cannot_supply_two_levels(): void
    {
        $staff = $this->userHolding('staff@t', 'prc.create', 'prc.update', 'prc.view');
        $director = $this->userHolding('dir@t', 'prc.approve', 'prc.approve-director');

        $vendor = $this->vendor();
        $rfq = $this->rfqWithQuote($vendor, 200_000_000, $staff);

        $id = (int) $this->award($staff, $rfq, $vendor, 200_000_000, 200_000_000)->assertStatus(201)->json('data.id');
        $this->actingAs($staff)->postJson("/api/procurement/award-decisions/{$id}/submit")->assertOk();

        // A director gives level 1...
        $this->actingAs($director)->postJson("/api/procurement/award-decisions/{$id}/approve")->assertOk();
        // ...and cannot also give level 2 — the ladder wants DISTINCT approvers.
        $this->actingAs($director)->postJson("/api/procurement/award-decisions/{$id}/approve")->assertStatus(422);
        $this->assertSame(DocumentStatus::Submitted, AwardDecision::query()->find($id)->status);
    }

    public function test_a_one_and_a_half_billion_award_needs_three_distinct_approvers(): void
    {
        $staff = $this->userHolding('staff@t', 'prc.create', 'prc.update', 'prc.view');
        $approver = $this->userHolding('appr@t', 'prc.approve');
        $directorA = $this->userHolding('dirA@t', 'prc.approve', 'prc.approve-director');
        $directorB = $this->userHolding('dirB@t', 'prc.approve', 'prc.approve-director');

        $vendor = $this->vendor();
        $rfq = $this->rfqWithQuote($vendor, 1_500_000_000, $staff);

        $id = (int) $this->award($staff, $rfq, $vendor, 1_500_000_000, 1_500_000_000)->assertStatus(201)->json('data.id');
        $this->actingAs($staff)->postJson("/api/procurement/award-decisions/{$id}/submit")->assertOk();

        $this->actingAs($approver)->postJson("/api/procurement/award-decisions/{$id}/approve")->assertOk();
        $this->assertSame(DocumentStatus::Submitted, AwardDecision::query()->find($id)->status);

        // Level 2 — a director.
        $this->actingAs($directorA)->postJson("/api/procurement/award-decisions/{$id}/approve")->assertOk();
        $this->assertSame(DocumentStatus::Submitted, AwardDecision::query()->find($id)->status);

        // Level 3 — the third distinct approver, also from the director permission.
        $this->actingAs($directorB)->postJson("/api/procurement/award-decisions/{$id}/approve")->assertOk();
        $this->assertSame(DocumentStatus::Approved, AwardDecision::query()->find($id)->status);
    }

    public function test_the_submitter_cannot_approve_their_own_award(): void
    {
        $staff = $this->userHolding('staff@t', 'prc.create', 'prc.update', 'prc.view', 'prc.approve');

        $vendor = $this->vendor();
        $rfq = $this->rfqWithQuote($vendor, 50_000_000, $staff);

        $id = (int) $this->award($staff, $rfq, $vendor, 50_000_000, 50_000_000)->assertStatus(201)->json('data.id');
        $this->actingAs($staff)->postJson("/api/procurement/award-decisions/{$id}/submit")->assertOk();

        // Maker-checker: the submitter is refused (composes with the ladder).
        $this->actingAs($staff)->postJson("/api/procurement/award-decisions/{$id}/approve")->assertStatus(422);
    }

    // ================================================= deviation reason

    public function test_deciding_above_the_rab_requires_a_deviation_reason(): void
    {
        $staff = $this->userHolding('staff@t', 'prc.create', 'prc.update', 'prc.view');
        $vendor = $this->vendor();
        $rfq = $this->rfqWithQuote($vendor, 250_000_000, $staff);

        // awarded 250jt > rab 200jt -> deviasi 50jt, tanpa alasan -> 422.
        $this->award($staff, $rfq, $vendor, 200_000_000, 250_000_000)->assertStatus(422);

        // With a reason it is accepted.
        $this->award($staff, $rfq, $vendor, 200_000_000, 250_000_000, 'Spesifikasi naik atas permintaan MK.')
            ->assertStatus(201);
    }

    // ================================================= criterion #4 (b)

    public function test_an_award_with_a_changed_price_cannot_be_submitted_without_a_negotiation_minute(): void
    {
        $staff = $this->userHolding('staff@t', 'prc.create', 'prc.update', 'prc.view');
        $vendor = $this->vendor();
        // Penawaran terakhir 200jt; award dinegosiasi turun ke 180jt (harga berubah).
        $rfq = $this->rfqWithQuote($vendor, 200_000_000, $staff);

        $id = (int) $this->award($staff, $rfq, $vendor, 200_000_000, 180_000_000)->assertStatus(201)->json('data.id');

        // Tanpa BAN, submit ditolak dengan pesan yang menyebut BAN.
        $response = $this->actingAs($staff)->postJson("/api/procurement/award-decisions/{$id}/submit")->assertStatus(422);
        $this->assertStringContainsString('Berita Acara Negosiasi', (string) $response->json('message'));

        // Buat BAN untuk (RFQ, vendor); submit lolos.
        $this->actingAs($staff)->postJson('/api/procurement/negotiation-minutes', [
            'rfq_id' => $rfq->id, 'vendor_id' => $vendor->id, 'meeting_date' => '2026-08-10',
            'items' => [['description' => 'Genset 100 kVA', 'harga_awal' => 200_000_000, 'harga_nego' => 180_000_000]],
        ])->assertStatus(201);

        $this->actingAs($staff)->postJson("/api/procurement/award-decisions/{$id}/submit")->assertOk();
    }

    public function test_an_award_at_the_quoted_price_needs_no_negotiation_minute(): void
    {
        $staff = $this->userHolding('staff@t', 'prc.create', 'prc.update', 'prc.view');
        $vendor = $this->vendor();
        $rfq = $this->rfqWithQuote($vendor, 200_000_000, $staff);

        // awarded == penawaran terakhir -> tidak ada negosiasi -> tidak perlu BAN.
        $id = (int) $this->award($staff, $rfq, $vendor, 200_000_000, 200_000_000)->assertStatus(201)->json('data.id');
        $this->actingAs($staff)->postJson("/api/procurement/award-decisions/{$id}/submit")->assertOk();
    }

    // ================================================= criterion #4 (a)

    public function test_a_po_from_an_rfq_cannot_be_approved_without_an_approved_award(): void
    {
        $staff = $this->userHolding('staff@t', 'prc.create', 'prc.update', 'prc.view');
        $approver = $this->userHolding('appr@t', 'prc.approve');
        $vendor = $this->vendor();
        $rfq = $this->rfqWithQuote($vendor, 50_000_000, $staff);

        // Pilih pemenang lalu buat PO dari RFQ (PO ber-rfq_id).
        $this->actingAs($staff)->postJson("/api/procurement/rfqs/{$rfq->id}/choose-winner", ['vendor_id' => $vendor->id])->assertOk();
        $poId = (int) $this->actingAs($staff)->postJson("/api/procurement/rfqs/{$rfq->id}/create-po", ['vendor_id' => $vendor->id])
            ->assertStatus(201)->json('data.id');

        $this->assertSame($rfq->id, PurchaseOrder::query()->find($poId)->rfq_id);

        $this->actingAs($staff)->postJson("/api/procurement/purchase-orders/{$poId}/submit")->assertOk();

        // Tanpa award disetujui, approve PO ditolak 422 menyebut RFQ-nya.
        $blocked = $this->actingAs($approver)->postJson("/api/procurement/purchase-orders/{$poId}/approve")->assertStatus(422);
        $this->assertStringContainsString($rfq->code, (string) $blocked->json('message'));

        // Terbitkan & setujui award, lalu approve PO lolos.
        $awardId = (int) $this->award($staff, $rfq, $vendor, 50_000_000, 50_000_000)->assertStatus(201)->json('data.id');
        $this->actingAs($staff)->postJson("/api/procurement/award-decisions/{$awardId}/submit")->assertOk();
        $this->actingAs($approver)->postJson("/api/procurement/award-decisions/{$awardId}/approve")->assertOk();

        $this->actingAs($approver)->postJson("/api/procurement/purchase-orders/{$poId}/approve")->assertOk();
        $this->assertSame(DocumentStatus::Approved, PurchaseOrder::query()->find($poId)->status);
    }

    public function test_a_plain_po_not_from_an_rfq_is_unaffected_by_the_award_gate(): void
    {
        $staff = $this->userHolding('staff@t', 'prc.create', 'prc.update', 'prc.view');
        $approver = $this->userHolding('appr@t', 'prc.approve');
        $vendor = $this->vendor();

        $poId = (int) $this->actingAs($staff)->postJson('/api/procurement/purchase-orders', [
            'vendor_id' => $vendor->id, 'order_date' => '2026-08-08', 'expected_date' => '2026-08-22', 'payment_term_days' => 30,
            'pr_bypass_reason' => 'Fixture uji: pembelian langsung tanpa PR', // wajib sejak T3.8
            'items' => [['description' => 'Pasir', 'qty' => 10, 'unit' => 'm3', 'unit_price' => 200_000]],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($staff)->postJson("/api/procurement/purchase-orders/{$poId}/submit")->assertOk();
        // rfq_id null -> gate inert -> approve lolos.
        $this->actingAs($approver)->postJson("/api/procurement/purchase-orders/{$poId}/approve")->assertOk();

        $this->assertSame(DocumentStatus::Approved, PurchaseOrder::query()->find($poId)->status);
    }
}
