<?php

namespace Tests\Feature\Subcontract;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\Tax;
use Modules\Finance\Services\ApBillService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Subcontract\Models\Subcontract;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * Who may press the retention-release button.
 *
 * The endpoint mints an ALREADY-APPROVED AP bill: RetentionService submits it
 * as nobody and approves it as the releaser, so maker-checker finds no
 * submitter and stays quiet by design. That is only defensible if the releaser
 * genuinely holds the AP approval right — yet the route was gated on scm.post
 * alone, a Subcontract permission implying no Finance right, while the service
 * docblock claimed the act was "gated by fin.post". Verified consequence: a
 * user holding ONLY scm.post minted approved bill BIL/2026/VIII/0002 for
 * Rp 5.000.000, immediately payable through PaymentService. The route now
 * demands scm.post AND fin.approve; these tests pin both sides of that gate.
 */
class RetentionReleaseGateTest extends ErpTestCase
{
    use SubcontractFixtures;

    private const RETENTION = 5000000.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // PPh final jasa konstruksi, so the opname bill withholds down the real
        // 2-1230 path instead of the untyped fallback.
        Tax::create([
            'code' => Tax::pphFinalCodeForScheme('pelaksanaan_bersertifikat'),
            'name' => 'PPh Final Konstruksi 2,65%',
            'rate' => 2.65,
            'tax_type' => 'pph_withholding',
            'coa_account_id' => (int) Account::query()->where('code', '2-1230')->value('id'),
        ]);
    }

    /**
     * An SPK with Rp 5.000.000 of retention actually credited to 2-1500 — the
     * one state in which a release can legitimately be raised.
     */
    private function spkWithBilledRetention(): Subcontract
    {
        $spk = $this->makeApprovedSubcontract([
            'value' => 200000000.0,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'pph_rate' => 2.65,
            // Masa pemeliharaan berakhir sebelum tanggal pelepasan (2026-05-10):
            // berkas ini menguji gate IZIN, bukan gate waktu temuan #75.
            'defect_liability_until' => '2026-05-01',
        ]);

        $line = $this->addLine($spk, [
            'description' => 'Pekerjaan struktur beton',
            'qty' => 1,
            'unit_price' => 200000000.0,
            'amount' => 200000000.0,
        ]);

        $claim = $this->approvedClaim($spk, [$line->id => 50]);

        $bill = app(ApBillService::class)->createFromSubconClaim($claim, ['bill_date' => '2026-04-05']);
        $bill->submit($this->actor());
        app(ApBillService::class)->approve($bill, $this->approver());

        return $spk;
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

    private function postRelease(Subcontract $spk)
    {
        return $this->postJson("/api/subcontract/subcontracts/{$spk->id}/retention-release", [
            'amount' => self::RETENTION,
            'release_date' => '2026-05-10',
            'notes' => 'BAST II, masa pemeliharaan selesai',
        ]);
    }

    public function test_scm_post_without_fin_approve_is_refused_and_mints_nothing(): void
    {
        $spk = $this->spkWithBilledRetention();
        $billsBefore = ApBill::query()->count();

        Sanctum::actingAs($this->userHolding('subkon-admin@test.local', 'scm.post'));

        $this->postRelease($spk)->assertForbidden();

        // Refused at the door means refused entirely: no approved payable
        // appeared, and the SPK still reports the retention as held.
        $this->assertSame($billsBefore, ApBill::query()->count());
        $this->assertSame(0, $spk->retentionReleases()->count());
        $this->assertEqualsWithDelta(
            self::RETENTION,
            $this->retentionService()->balance($spk)['balance'],
            0.01,
        );
    }

    public function test_fin_approve_without_scm_post_is_refused_too(): void
    {
        $spk = $this->spkWithBilledRetention();

        // The finance-manager profile: fin.approve without any scm right.
        // AP approval alone must not reach into the Subcontract module either.
        Sanctum::actingAs($this->userHolding('finance-manager@test.local', 'fin.approve'));

        $this->postRelease($spk)->assertForbidden();

        $this->assertSame(0, $spk->retentionReleases()->count());
    }

    public function test_a_releaser_holding_both_permissions_still_releases_and_owns_the_approved_row(): void
    {
        $spk = $this->spkWithBilledRetention();

        $releaser = $this->userHolding('kabag-keuangan@test.local', 'scm.post', 'fin.approve');
        Sanctum::actingAs($releaser);

        $this->postRelease($spk)->assertCreated();

        $release = $spk->retentionReleases()->sole();
        /** @var ApBill $bill */
        $bill = ApBill::query()->findOrFail($release->ap_bill_id);

        $this->assertSame(DocumentStatus::Approved, $bill->status);
        $this->assertEqualsWithDelta(self::RETENTION, (float) $bill->total_payable, 0.01);

        // The trail must read "Diajukan: Sistem / Disetujui: <releaser>" — a
        // system submission, and an approved row carried by the human whose
        // fin.approve the route just verified.
        $this->assertNull($bill->approvals()->where('action', 'submitted')->sole()->user_id);
        $this->assertSame(
            (int) $releaser->id,
            (int) $bill->approvals()->where('action', 'approved')->sole()->user_id,
        );
    }
}
