<?php

namespace Tests\Feature\Core;

use Modules\Core\Services\SettingService;
use Modules\Core\Support\ApprovableDocuments;
use Modules\Core\Support\ApprovalPolicy;
use Modules\Core\Traits\Approvable;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * SETIAP SEL AMBANG DI LAYAR PUNYA PENEGAK (verifikasi F-1, 7 Sep 2026).
 *
 * F-1 yang dikirim menawarkan ambang pada baris "Pembayaran keluar" dan
 * mencapnya `director: true` pada baris pengajuan — tetapi satu-satunya
 * pembaca stempel itu ada di dalam trait Approvable, dan Payment tidak
 * memakai trait itu. Terukur: pembayaran Rp 111.000.000, ambang Rp 1,
 * penyetuju tanpa fin.approve-director, DISETUJUI. Jejaknya mencatat bahwa
 * seorang direktur dituntut dan uangnya keluar tanpa satu pun.
 *
 * Uji ini menjaga bentuk umumnya, bukan satu barisnya: sebuah jenis dokumen
 * ke-29 yang tidak menegakkan apa pun tidak boleh mendapat sel ambang.
 */
class ApprovalDirectorReachTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        ApprovalPolicy::flushSchemaMemo();
    }

    /** @return array<string, mixed> */
    private function matrixGroup(): array
    {
        foreach (app(SettingService::class)->overview() as $group) {
            if ($group['key'] === 'approval_matrix') {
                return $group;
            }
        }

        $this->fail('the settings registry has no approval_matrix group');
    }

    /**
     * Dua jenis dari dua puluh delapan tidak memakai trait Approvable, dan
     * namanya ditulis di sini karena keduanya adalah kekecualian yang harus
     * diperiksa satu per satu, bukan pola yang boleh bertambah diam-diam.
     */
    public function test_two_types_walk_the_lifecycle_without_the_trait(): void
    {
        $without = [];

        foreach (array_keys(ApprovableDocuments::all()) as $class) {
            if (! in_array(Approvable::class, class_uses_recursive($class), true)) {
                $without[] = ApprovalPolicy::slugFor($class);
            }
        }

        sort($without);

        $this->assertSame(['payment', 'project_baseline'], $without);
    }

    /**
     * DAN SETIAP BARIS YANG MENDAPAT KOTAK ISIAN AMBANG LOLOS SALAH SATU DARI
     * TIGA JALAN PENEGAKAN. Ini uji yang akan merah bila jenis ke-29 mendapat
     * sel yang tidak ada yang membacanya.
     */
    public function test_every_row_that_offers_a_threshold_has_something_that_enforces_it(): void
    {
        $group = $this->matrixGroup();
        $offered = array_values(array_filter(
            array_column($group['settings'], 'doc_type'),
            fn (?string $type): bool => $type !== null,
        ));

        $this->assertNotEmpty($offered);

        foreach (array_unique($offered) as $type) {
            $this->assertTrue(
                ApprovalPolicy::enforcesStampedDirector($type),
                "the matrix offers a threshold on {$type}, and nothing reads it at approval time",
            );
        }

        foreach ($group['matrix'] as $row) {
            $this->assertSame(
                ApprovalPolicy::enforcesStampedDirector($row['type']),
                $row['threshold_enforced'],
                "matrix row {$row['type']} disagrees with ApprovalPolicy::enforcesStampedDirector",
            );
        }
    }

    /**
     * Pembayaran keluar TETAP mendapat selnya — perbaikannya adalah
     * menegakkannya, bukan mencabutnya. Yang berubah adalah bahwa sekarang ada
     * yang membacanya (PaymentService::approve).
     */
    public function test_the_outgoing_payment_row_keeps_its_threshold_because_its_service_now_enforces_it(): void
    {
        $this->assertContains('payment', ApprovalPolicy::ENFORCED_BY_ITS_SERVICE);
        $this->assertTrue(ApprovalPolicy::enforcesStampedDirector('payment'));

        $keys = array_column($this->matrixGroup()['settings'], 'key');
        $this->assertContains('approvals.payment.threshold_two_level', $keys);

        $source = file_get_contents(base_path('Modules/Finance/Services/PaymentService.php'));
        $this->assertStringContainsString('ApprovalPolicy::assertStampedDirector($payment, $by)', $source);
    }
}
