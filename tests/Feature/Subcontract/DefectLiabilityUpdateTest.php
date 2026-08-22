<?php

namespace Tests\Feature\Subcontract;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Subcontract\Models\Subcontract;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * PUT subcontracts/{id}/defect-liability — pintu sempit temuan #75 (susulan).
 *
 * Gate waktu pelepasan retensi membaca defect_liability_until, tetapi kolom
 * itu hanya bisa diisi selagi SPK masih draf (SubcontractService::update
 * menolak SPK non-editable). Portofolio SPK hidup yang sudah disetujui SEBELUM
 * gate lahir karenanya tidak pernah bisa melengkapi tanggalnya: setiap
 * pelepasan retensinya terpaksa override selamanya — jejak override yang
 * seharusnya menandai pengecualian berubah menjadi kebisingan rutin.
 *
 * Pintu ini mengubah SATU kolom itu saja, pada SPK submitted/approved, dan
 * ditolak begitu retensi pernah dilepas: tanggal yang diganti SETELAH
 * pelepasan menulis ulang cerita yang jejak override-nya sudah rekam.
 */
class DefectLiabilityUpdateTest extends ErpTestCase
{
    use SubcontractFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // -------------------------------------------------------------- fixtures

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

    private function putDate(Subcontract $spk, array $payload)
    {
        return $this->putJson(
            "/api/subcontract/subcontracts/{$spk->id}/defect-liability",
            $payload,
        );
    }

    // ------------------------------------------------------------ happy path

    public function test_the_date_can_be_set_on_an_approved_spk(): void
    {
        $spk = $this->makeApprovedSubcontract(['value' => 200_000_000.0]);
        $this->assertNull($spk->defect_liability_until);

        Sanctum::actingAs($this->userHolding('scm-admin@test.local', 'scm.update'));

        $this->putDate($spk, ['defect_liability_until' => '2027-03-31'])->assertOk();

        $this->assertSame('2027-03-31', $spk->refresh()->defect_liability_until?->toDateString());
    }

    public function test_a_submitted_spk_can_record_the_date_too(): void
    {
        $spk = $this->makeSubcontract([
            'value' => 200_000_000.0,
            'status' => DocumentStatus::Submitted,
        ]);

        Sanctum::actingAs($this->userHolding('scm-admin2@test.local', 'scm.update'));

        $this->putDate($spk, ['defect_liability_until' => '2027-06-30'])->assertOk();

        $this->assertSame('2027-06-30', $spk->refresh()->defect_liability_until?->toDateString());
    }

    /** Satu kolom itu saja: nilai dan retensi SPK tidak ikut bergerak. */
    public function test_only_the_defect_liability_date_moves(): void
    {
        $spk = $this->makeApprovedSubcontract([
            'value' => 200_000_000.0,
            'retention_pct' => 5.0,
        ]);

        Sanctum::actingAs($this->userHolding('scm-admin3@test.local', 'scm.update'));

        $this->putDate($spk, [
            'defect_liability_until' => '2027-03-31',
            'value' => 1.0,
            'retention_pct' => 0.0,
            'status' => 'draft',
        ])->assertOk();

        $spk->refresh();
        $this->assertEqualsWithDelta(200_000_000.0, (float) $spk->value, 0.01);
        $this->assertEqualsWithDelta(5.0, (float) $spk->retention_pct, 0.01);
        $this->assertSame(DocumentStatus::Approved, $spk->status);
    }

    // -------------------------------------------------------------- refusals

    /** SPK draf/ditolak masih bisa diedit lewat form biasa — pintu ini bukan untuknya. */
    public function test_a_draft_spk_is_pointed_at_the_ordinary_edit(): void
    {
        $spk = $this->makeSubcontract(['value' => 200_000_000.0]);

        Sanctum::actingAs($this->userHolding('scm-admin4@test.local', 'scm.update'));

        $this->putDate($spk, ['defect_liability_until' => '2027-03-31'])->assertStatus(422);

        $this->assertNull($spk->refresh()->defect_liability_until);
    }

    /**
     * Retensi sudah pernah dilepas: gate waktunya sudah terpakai. Mengganti
     * tanggal sesudahnya memalsukan alasan kenapa pelepasan itu (tidak)
     * meminta override — baris pelepasan lama tampak patuh/melanggar tanggal
     * yang tidak pernah berlaku saat itu.
     */
    public function test_refused_once_retention_has_been_released(): void
    {
        $spk = $this->makeApprovedSubcontract(['value' => 200_000_000.0]);

        // Baris pelepasan warisan (pra-jalur ledger, ap_bill_id null) —
        // bentuk data sah yang releasedRetention() hitung secara eksplisit.
        $spk->retentionReleases()->create([
            'release_date' => '2026-06-01',
            'amount' => 5_000_000.0,
            'notes' => 'Pelepasan tahap 1',
        ]);

        Sanctum::actingAs($this->userHolding('scm-admin5@test.local', 'scm.update'));

        $this->putDate($spk, ['defect_liability_until' => '2027-03-31'])->assertStatus(422);

        $this->assertNull($spk->refresh()->defect_liability_until);
    }

    public function test_the_route_demands_scm_update(): void
    {
        $spk = $this->makeApprovedSubcontract(['value' => 200_000_000.0]);

        Sanctum::actingAs($this->userHolding('viewer@test.local', 'scm.view'));

        $this->putDate($spk, ['defect_liability_until' => '2027-03-31'])->assertForbidden();

        $this->assertNull($spk->refresh()->defect_liability_until);
    }

    public function test_a_malformed_date_is_refused_by_validation(): void
    {
        $spk = $this->makeApprovedSubcontract(['value' => 200_000_000.0]);

        Sanctum::actingAs($this->userHolding('scm-admin6@test.local', 'scm.update'));

        $this->putDate($spk, ['defect_liability_until' => 'bukan-tanggal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('defect_liability_until');

        $this->assertNull($spk->refresh()->defect_liability_until);
    }
}
