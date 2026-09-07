<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\ApprovalLevels;
use Modules\Core\Support\ApprovalPolicy;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Subcontract\Models\Subcontract;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * KESETARAAN, BUKAN MIGRASI (F-1 / T1.3).
 *
 * ROADMAP eksplisit: gerbang lama PO/SPK TIDAK dipindahkan ke mekanisme baru.
 * Menulis ulang sebuah gerbang yang hijau dan sudah diaudit tidak membeli apa
 * pun dan mempertaruhkan uang — SPK/2026/II/0001 (Rp 6,5 miliar) adalah bukti
 * hidup apa yang terjadi ketika bendera dan penegaknya berpisah.
 *
 * Yang dituntut sebagai gantinya adalah bukti bahwa ApprovalPolicy MENJAWAB
 * PERSIS SAMA dengan gerbang yang berjalan hari ini. Diuji di enam nilai di
 * sekitar tiap ambang, karena setiap cacat ambang yang pernah ditemukan
 * repositori ini ada di batasnya sendiri: nol, jauh di bawah, satu rupiah di
 * bawah, TEPAT di ambang, satu rupiah di atas, jauh di atas.
 *
 * Perbandingannya bukan rumus lawan rumus melainkan STEMPEL SUNGGUHAN lawan
 * kebijakan: setiap nilai benar-benar diajukan, dan needs_director_approval
 * yang ditulis submit() itulah yang dibandingkan. Sebuah uji yang menghitung
 * ulang rumus yang sama dua kali akan tetap hijau ketika keduanya salah
 * bersama.
 */
class ApprovalPolicyEquivalenceTest extends ErpTestCase
{
    use SubcontractFixtures;

    /**
     * Enam nilai di sekitar sebuah ambang. Nol ikut karena "nol/null" adalah
     * kasus yang paling mudah salah: sebuah dokumen tanpa nilai bukan dokumen
     * bernilai nol, dan sebuah ambang yang hilang bukan ambang nol.
     *
     * @return list<float>
     */
    private function sixValuesAround(float $threshold): array
    {
        return [
            0.0,
            $threshold / 2,
            $threshold - 1,
            $threshold,
            $threshold + 1,
            $threshold * 10,
        ];
    }

    private function maker(string $email): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pengaju '.$email,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);

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

    // ------------------------------------------------------------------ PO

    public function test_the_policy_and_the_shipped_po_gate_agree_at_six_values_around_the_threshold(): void
    {
        $maker = $this->maker('pengaju-po@test.local');
        $threshold = PurchaseOrder::directorApprovalThreshold();
        $policy = ApprovalPolicy::forType('purchase_order');

        $this->assertSame($threshold, $policy->threshold, 'the matrix must carry the shipped threshold');
        $this->assertSame(ApprovalPolicy::MODE_SINGLE_DIRECTOR, $policy->mode);

        foreach ($this->sixValuesAround($threshold) as $value) {
            $po = $this->submittedPo($value, $maker);

            $this->assertSame(
                (bool) $po->needs_director_approval,
                $policy->directorRequiredForSingleApproval($value),
                sprintf('PO senilai %s: gerbang lama dan kebijakan baru berbeda pendapat.', number_format($value)),
            );

            // single_director tidak pernah menuntut penyetuju KEDUA: yang
            // dituntutnya adalah penyetuju yang lebih senior, bukan lebih
            // banyak. Ini yang membedakannya dari extra_level.
            $this->assertSame(1, $policy->levelsFor($value));
        }
    }

    public function test_the_stamped_po_policy_says_the_same_thing_as_the_stamped_flag(): void
    {
        $maker = $this->maker('pengaju-po-stempel@test.local');
        $threshold = PurchaseOrder::directorApprovalThreshold();

        foreach ($this->sixValuesAround($threshold) as $value) {
            $po = $this->submittedPo($value, $maker);
            $stamp = ApprovalPolicy::stampedFor($po);

            $this->assertNotNull($stamp, 'submit must stamp the policy on the submitted row');
            $this->assertSame((bool) $po->needs_director_approval, (bool) $stamp['director']);
            $this->assertSame(1, (int) $stamp['levels']);
            $this->assertSame($value, (float) $stamp['amount']);
            $this->assertSame($threshold, (float) $stamp['threshold']);
        }
    }

    // ----------------------------------------------------------------- SPK

    public function test_the_policy_and_the_shipped_spk_gate_agree_at_six_values_around_the_threshold(): void
    {
        $maker = $this->maker('pengaju-spk@test.local');
        $threshold = Subcontract::directorApprovalThreshold();
        $policy = ApprovalPolicy::forType('subcontract');

        $this->assertSame($threshold, $policy->threshold);
        $this->assertSame(ApprovalPolicy::MODE_SINGLE_DIRECTOR, $policy->mode);

        foreach ($this->sixValuesAround($threshold) as $value) {
            $spk = $this->makeSubcontract(['value' => $value])->submit($maker);

            $this->assertSame(
                (bool) $spk->needs_director_approval,
                $policy->directorRequiredForSingleApproval($value),
                sprintf('SPK senilai %s: gerbang lama dan kebijakan baru berbeda pendapat.', number_format($value)),
            );
            $this->assertSame(1, $policy->levelsFor($value));
        }
    }

    /**
     * Addendum SPK memakai ambang SPK — dan barisnya di matriks memantul ke
     * baris SPK, jadi keduanya tidak bisa berbeda pendapat by construction.
     */
    public function test_the_addendum_row_resolves_through_the_subcontract_row(): void
    {
        $this->assertSame('subcontract', ApprovalPolicy::settingType('subcontract_addendum'));
        $this->assertSame(
            ApprovalPolicy::keysFor('subcontract')['threshold'],
            ApprovalPolicy::keysFor('subcontract_addendum')['threshold'],
        );
        $this->assertSame(
            ApprovalPolicy::forType('subcontract')->threshold,
            ApprovalPolicy::forType('subcontract_addendum')->threshold,
        );
    }

    // ------------------------------------------------------- award decision

    /**
     * Jenjang P2 ditulis ulang dalam kosakata matriks, dan keduanya menjawab
     * sama di enam nilai di sekitar KEDUA batasnya.
     */
    public function test_the_policy_and_the_shipped_award_ladder_agree_at_six_values_around_each_boundary(): void
    {
        $policy = ApprovalPolicy::forType('award_decision');

        $this->assertSame(ApprovalPolicy::MODE_EXTRA_LEVEL, $policy->mode);
        $this->assertSame(100000000.0, $policy->threshold);
        $this->assertSame(1000000000.0, $policy->thirdLevelThreshold);

        $values = array_merge(
            $this->sixValuesAround(100000000.0),
            $this->sixValuesAround(1000000000.0),
        );

        foreach ($values as $value) {
            $this->assertSame(
                ApprovalLevels::forAmount('award_decision', $value),
                $policy->levelsFor($value),
                sprintf('Award senilai %s: jenjang config dan kebijakan berbeda pendapat.', number_format($value)),
            );
        }
    }

    /**
     * Yang mengunci semantik batasnya: TEPAT di ambang sudah masuk tingkat
     * yang lebih tinggi (`to` eksklusif di ApprovalLevels, `>=` di
     * needs_director_approval). Angka-angka ini ditulis apa adanya supaya
     * sebuah perubahan bawaan config menjatuhkan uji ini, bukan menggesernya
     * diam-diam bersama rumusnya.
     */
    public function test_a_value_exactly_at_a_boundary_falls_into_the_higher_tier(): void
    {
        $policy = ApprovalPolicy::forType('award_decision');

        $this->assertSame(1, $policy->levelsFor(99999999.0));
        $this->assertSame(2, $policy->levelsFor(100000000.0));
        $this->assertSame(2, $policy->levelsFor(999999999.0));
        $this->assertSame(3, $policy->levelsFor(1000000000.0));

        $po = ApprovalPolicy::forType('purchase_order');
        $this->assertFalse($po->directorRequiredForSingleApproval(99999999.0));
        $this->assertTrue($po->directorRequiredForSingleApproval(100000000.0));
    }

    /**
     * Sebuah dokumen TANPA nilai bukan dokumen bernilai nol: kebijakan yang
     * tidak punya nilai untuk diukur tidak menuntut direktur, dan tidak
     * menuntut tingkat kedua.
     */
    public function test_a_document_with_no_amount_is_not_a_document_worth_zero(): void
    {
        $policy = ApprovalPolicy::forType('award_decision');

        $this->assertSame(1, $policy->levelsFor(null));
        $this->assertFalse(ApprovalPolicy::forType('purchase_order')->directorRequiredForSingleApproval(null));
    }

    /**
     * Dua puluh lima jenis tanpa ambang: aturannya, bukan nol.
     */
    public function test_the_twenty_five_types_with_no_gate_today_carry_no_threshold(): void
    {
        $withGate = ['purchase_order', 'subcontract', 'subcontract_addendum', 'award_decision'];
        $withoutGate = 0;

        foreach (ApprovalPolicy::all() as $type => $policy) {
            if (in_array($type, $withGate, true)) {
                $this->assertNotNull($policy->threshold, "[{$type}] must ship with today's threshold");

                continue;
            }

            $withoutGate++;
            $this->assertNull($policy->threshold, sprintf(
                '[%s] has no approval gate today; shipping it with a threshold would change who may '
                .'approve it the moment this package is deployed.',
                $type,
            ));
            $this->assertSame(1, $policy->levelsFor(999999999999.0));
            $this->assertFalse($policy->directorRequiredForSingleApproval(999999999999.0));
        }

        // 28 jenis, 4 bergerbang (PO, SPK, addendum SPK, keputusan pemenang).
        $this->assertSame(24, $withoutGate);
    }

    public function test_the_award_decision_ladder_and_the_matrix_row_stay_in_step(): void
    {
        // Kalau seseorang mengubah salah satu blok config tanpa yang lain,
        // uji kesetaraan di atas jatuh — baris ini menamai penyebabnya.
        $ladder = config('erp.approvals.award_decision.ladder');

        $this->assertSame(100000000, $ladder[0]['to']);
        $this->assertSame(1000000000, $ladder[1]['to']);
        $this->assertSame(100000000, config('erp.approvals.award_decision.threshold_two_level'));
        $this->assertSame(1000000000, config('erp.approvals.award_decision.third_level_threshold'));
    }
}
