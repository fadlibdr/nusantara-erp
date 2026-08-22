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
 * The SPK half of the director gate — the half with the live scar.
 *
 * SPK/2026/II/0001, Rp 6.500.000.000 — 32,5× the Rp 200 juta threshold — was
 * submitted AND approved by one non-director login while its screen displayed
 * "Perlu persetujuan direktur": the flag was stamped, returned by the API and
 * never read at approval time. SubcontractService::approve now runs the shared
 * DirectorApproval guard, keyed on scm.approve-director. The guard is
 * forward-only: that SPK stays approved, and these tests pin what happens to
 * every one submitted after it.
 */
class SubcontractDirectorApprovalTest extends ErpTestCase
{
    use SubcontractFixtures;

    /** SPK/2026/II/0001's own number: 32,5× the Rp 200.000.000 threshold. */
    private const ABOVE_THRESHOLD = 6500000000.0;

    private const BELOW_THRESHOLD = 150000000.0;

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

    private function submittedSpk(float $value, User $maker): Subcontract
    {
        return $this->makeSubcontract(['value' => $value])->submit($maker);
    }

    private function approveVia(Subcontract $spk)
    {
        return $this->postJson("/api/subcontract/subcontracts/{$spk->id}/approve");
    }

    public function test_a_plain_approver_is_refused_an_above_threshold_spk_and_told_who_can(): void
    {
        $spk = $this->submittedSpk(self::ABOVE_THRESHOLD, $this->userHolding('pm@site.local', 'scm.update'));
        $this->assertTrue($spk->needs_director_approval, 'submit must have stamped the flag');

        Sanctum::actingAs($this->userHolding('manajer-konstruksi@site.local', 'scm.approve'));

        $response = $this->approveVia($spk)->assertStatus(422);

        // The refusal names the document, both numbers and the way out.
        $message = (string) $response->json('message');
        $this->assertStringContainsString($spk->code, $message);
        $this->assertStringContainsString('Rp 6.500.000.000', $message);
        $this->assertStringContainsString('Rp 200.000.000', $message);
        $this->assertStringContainsString('scm.approve-director', $message);

        $this->assertSame(DocumentStatus::Submitted, $spk->fresh()->status);
        $this->assertSame(0, $spk->approvals()->where('action', 'approved')->count());
    }

    public function test_a_director_approves_the_same_spk(): void
    {
        $spk = $this->submittedSpk(self::ABOVE_THRESHOLD, $this->userHolding('pm@site.local', 'scm.update'));

        Sanctum::actingAs($this->userHolding(
            'direktur@site.local',
            'scm.approve',
            'scm.approve-director',
        ));

        $this->approveVia($spk)->assertOk();

        $this->assertSame(DocumentStatus::Approved, $spk->fresh()->status);
    }

    public function test_an_spk_below_the_threshold_still_needs_no_director(): void
    {
        $spk = $this->submittedSpk(self::BELOW_THRESHOLD, $this->userHolding('pm@site.local', 'scm.update'));
        $this->assertFalse($spk->needs_director_approval);

        Sanctum::actingAs($this->userHolding('manajer-konstruksi@site.local', 'scm.approve'));

        $this->approveVia($spk)->assertOk();

        $this->assertSame(DocumentStatus::Approved, $spk->fresh()->status);
    }

    /**
     * The gates COMPOSE: a director who submitted the SPK is senior enough and
     * still not somebody else. Exactly the pair of clicks SPK/2026/II/0001's
     * history records must stay impossible even for a director.
     */
    public function test_a_director_who_submitted_the_spk_is_still_refused_as_its_maker(): void
    {
        $director = $this->userHolding(
            'direktur@site.local',
            'scm.update',
            'scm.approve',
            'scm.approve-director',
        );

        $spk = $this->submittedSpk(self::ABOVE_THRESHOLD, $director);

        Sanctum::actingAs($director);

        $response = $this->approveVia($spk)->assertStatus(422);

        $this->assertStringContainsString(
            'tidak boleh disetujui oleh pengajunya sendiri',
            (string) $response->json('message'),
        );
        $this->assertSame(DocumentStatus::Submitted, $spk->fresh()->status);
    }
}
