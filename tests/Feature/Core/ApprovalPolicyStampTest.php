<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\ApprovalPolicy;
use Modules\Estimation\Models\Boq;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\AwardDecision;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\Vendor;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * ATURAN YANG BERLAKU ADALAH ATURAN SAAT DOKUMEN DIAJUKAN (F-1 / T1.2).
 *
 * Cacat yang ditutup ini nyata dan ada di kode yang dikirim P2: jenjang
 * persetujuan dibaca LANGSUNG dari config setiap kali seseorang menekan
 * Setujui. Maka menaikkan sebuah ambang siang hari MENGURANGI tuntutan setiap
 * dokumen yang sedang menunggu — surut, diam-diam, dan tanpa satu baris pun
 * yang mencatat bahwa aturannya berpindah di tengah jalan. Sebuah award
 * Rp 1,5 miliar yang diajukan ketika tiga penyetuju dituntut bisa selesai
 * dengan dua.
 *
 * Sejak F-1 kebijakannya DICAP pada baris `submitted` dan itulah yang dibaca
 * saat menyetujui. Ujinya adalah urutan yang persis: ajukan, ubah kebijakan,
 * setujui — dan dokumennya mengikuti aturan saat ia diajukan.
 */
class ApprovalPolicyStampTest extends ErpTestCase
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
            'name' => 'Petugas '.$email,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function vendor(): Vendor
    {
        return Vendor::query()->create([
            'code' => 'VND-A1',
            'name' => 'PT Pemenang',
            'classification' => 'material',
            'is_pkp' => true,
            'is_subcontractor' => false,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
    }

    private function rfq(): Rfq
    {
        /** @var Rfq $rfq */
        return Rfq::query()->create([
            'rfq_date' => '2026-03-01',
            'due_date' => '2026-03-10',
            'status' => 'draft',
        ]);
    }

    private function submittedAward(float $amount, User $staff): AwardDecision
    {
        $vendor = $this->vendor();
        $rfq = $this->rfq();

        /** @var AwardDecision $award */
        $award = AwardDecision::query()->create([
            'rfq_id' => $rfq->id,
            'vendor_id' => $vendor->id,
            'rab_amount' => $amount,
            'awarded_amount' => $amount,
            'deviation_amount' => 0,
            'status' => DocumentStatus::Draft,
        ]);

        return $award->submit($staff);
    }

    /**
     * Menaikkan ambang tingkat ketiga SESUDAH pengajuan tidak boleh membuat
     * award Rp 1,5 miliar selesai dengan dua penyetuju.
     */
    public function test_raising_a_threshold_after_submission_does_not_lower_what_the_document_needs(): void
    {
        $staff = $this->userHolding('staff@t.local', 'prc.create');
        $award = $this->submittedAward(1_500_000_000, $staff);

        $this->assertSame(3, $award->requiredApprovalLevels(), 'submitted under the shipped ladder');

        // Pemilik menaikkan ambang tingkat ketiga jauh di atas nilai award ini.
        $this->asDirectorEditing('approvals.award_decision.third_level_threshold', 9_000_000_000);

        $this->assertSame(
            3,
            $award->fresh()->requiredApprovalLevels(),
            'the document must follow the policy it was submitted under',
        );
    }

    /** …dan menurunkannya tidak boleh menuntut penyetuju ketiga yang tidak ada saat diajukan. */
    public function test_lowering_a_threshold_after_submission_does_not_raise_what_the_document_needs(): void
    {
        $staff = $this->userHolding('staff@t.local', 'prc.create');
        $award = $this->submittedAward(200_000_000, $staff);

        $this->assertSame(2, $award->requiredApprovalLevels());

        $this->asDirectorEditing('approvals.award_decision.third_level_threshold', 100_000_000);

        $this->assertSame(2, $award->fresh()->requiredApprovalLevels());
    }

    /**
     * Urutan lengkapnya lewat HTTP: ajukan, ubah kebijakan, setujui —
     * dua penyetuju menyelesaikannya, seperti saat ia diajukan.
     */
    public function test_submit_then_change_the_policy_then_approve_follows_the_submitted_policy(): void
    {
        $staff = $this->userHolding('staff@t.local', 'est.create');
        $approver = $this->userHolding('appr@t.local', 'prc.approve');
        $director = $this->userHolding('dir@t.local', 'prc.approve', 'prc.approve-director');

        $award = $this->submittedAward(200_000_000, $staff);

        // Sesudah pengajuan, pemilik menaikkan ambang dua tingkat jauh di atas
        // nilai award ini: kalau resolusinya langsung, satu persetujuan biasa
        // akan menyelesaikannya.
        $this->asDirectorEditing('approvals.award_decision.threshold_two_level', 5_000_000_000);

        $this->actingAs($approver)->postJson("/api/procurement/award-decisions/{$award->id}/approve")->assertOk();
        $this->assertSame(
            DocumentStatus::Submitted,
            $award->fresh()->status,
            'one approval must NOT be enough: the document was submitted under a two-level policy',
        );

        $this->actingAs($director)->postJson("/api/procurement/award-decisions/{$award->id}/approve")->assertOk();
        $this->assertSame(DocumentStatus::Approved, $award->fresh()->status);
    }

    /**
     * Ambang yang dipasang pemilik pada jenis yang HARI INI tidak bergerbang
     * benar-benar ditegakkan — dan hanya untuk dokumen yang diajukan sesudah
     * ambang itu ada.
     */
    public function test_a_threshold_the_owner_sets_is_enforced_only_for_documents_submitted_afterwards(): void
    {
        $staff = $this->userHolding('staff@t.local', 'est.create');
        $approver = $this->userHolding('appr@t.local', 'est.approve');

        // Diajukan SEBELUM ambangnya ada: stempelnya berkata "tanpa direktur".
        $before = $this->submittedBoq(500_000_000, $staff);

        $this->asDirectorEditing('approvals.boq.threshold_two_level', 100_000_000);

        $this->actingAs($approver)
            ->postJson("/api/estimation/boqs/{$before->id}/approve")
            ->assertOk();

        // Diajukan SESUDAHNYA: penyetuju biasa ditolak, dan kalimatnya
        // menyebut izin yang dibutuhkan.
        $after = $this->submittedBoq(500_000_000, $staff);

        $response = $this->actingAs($approver)
            ->postJson("/api/estimation/boqs/{$after->id}/approve")
            ->assertStatus(422);

        $this->assertStringContainsString('est.approve-director', (string) $response->json('message'));
        $this->assertSame(DocumentStatus::Submitted, $after->fresh()->status);
    }

    public function test_the_stamp_names_the_rule_the_refusal_quotes(): void
    {
        $staff = $this->userHolding('staff@t.local', 'est.create');
        $this->asDirectorEditing('approvals.boq.threshold_two_level', 100_000_000);

        $boq = $this->submittedBoq(500_000_000, $staff);
        $stamp = ApprovalPolicy::stampedFor($boq);

        $this->assertNotNull($stamp);
        $this->assertSame('boq', $stamp['type']);
        $this->assertSame('est', $stamp['prefix']);
        $this->assertSame(100000000.0, (float) $stamp['threshold']);
        $this->assertSame(500000000.0, (float) $stamp['amount']);
        $this->assertTrue((bool) $stamp['director']);
        $this->assertSame(1, (int) $stamp['levels']);
    }

    /**
     * Dokumen yang diajukan SEBELUM kolom stempelnya ada (maju-saja) memakai
     * jalur lama, yaitu perilaku kemarin — bukan menjadi dokumen tanpa aturan.
     */
    public function test_a_document_submitted_before_the_stamp_existed_falls_back_to_live_resolution(): void
    {
        $staff = $this->userHolding('staff@t.local', 'prc.create');
        $award = $this->submittedAward(1_500_000_000, $staff);

        // Persis keadaan baris yang ditulis sebelum migrasi 000198.
        $award->approvals()->where('action', 'submitted')->update(['policy' => null]);

        $this->assertNull(ApprovalPolicy::stampedFor($award->fresh()));
        $this->assertSame(3, $award->fresh()->requiredApprovalLevels());
    }

    // ------------------------------------------------------------- fixtures

    /**
     * BOQ dipilih sebagai "jenis yang hari ini tidak bergerbang": ia membawa
     * kolom nilai (est_boqs.total) sehingga sebuah ambang PUNYA sesuatu untuk
     * diukur, dan tidak ada satu pun gerbang direktur di modulnya hari ini.
     */
    private function submittedBoq(float $amount, User $staff): Boq
    {
        /** @var Boq $boq */
        $boq = Boq::query()->create([
            'title' => 'RAB Struktur',
            'total' => $amount,
            'status' => DocumentStatus::Draft,
        ]);

        return $boq->submit($staff);
    }

    /**
     * Mengubah aturan persetujuan menuntut izin direktur (T1.1); tes ini
     * TENTANG stempel, bukan tentang penjaga itu, jadi suntingannya dilakukan
     * sebagai orang yang memang boleh.
     *
     * Izinnya diturunkan dari KUNCINYA sejak putaran verifikasi F-1: penjaga
     * itu dulu menerima izin direktur mana pun untuk baris mana pun, sehingga
     * satu prc.approve-director membuka approvals.boq.* juga.
     */
    private function asDirectorEditing(string $key, mixed $value): void
    {
        $permission = ApprovalPolicy::directorPermissionForKey($key) ?? 'prc.approve-director';
        $editor = $this->userHolding('editor-'.md5($key).'@t.local', 'core.update', $permission);

        $this->actingAs($editor);
        app(SettingService::class)->set($key, $value);
        app('auth')->forgetGuards();
    }
}
