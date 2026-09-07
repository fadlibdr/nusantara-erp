<?php

namespace Tests\Feature\Core;

use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\ApprovalPolicy;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * MODE "TAMBAHAN TINGKAT" HANYA DI TEMPAT PERSETUJUAN BOLEH BERHENTI DI TENGAH
 * (verifikasi F-1, 7 Sep 2026).
 *
 * F-1 yang dikirim menawarkan sel mode pada dua belas baris. Sebelas di
 * antaranya berbohong: mode itu membuat persetujuan PERTAMA meninggalkan
 * dokumen pada `submitted`, dan modulnya menjalankan akibat persetujuan di
 * baris berikutnya tanpa bertanya. Terukur pada invoice termin Rp 2,22 miliar —
 * jurnal diposting pada dokumen yang belum disetujui, lalu diposting kedua
 * kalinya. Berkas uji tetangganya (ArInvoiceExtraLevelRefusalTest) memaku
 * angka jurnalnya; yang dipaku di sini adalah jangkauan modenya.
 */
class ApprovalExtraLevelReachTest extends ErpTestCase
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
     * SATU JENIS, DAN NAMANYA DITULIS DI SINI. Sebuah uji yang menurunkan
     * daftarnya dari sumber yang sama dengan kodenya akan tetap hijau ketika
     * jenis ke-29 salah ikut — dan justru itu yang dijaga.
     */
    public function test_only_the_award_decision_may_carry_an_extra_level(): void
    {
        $laddered = array_values(array_filter(
            ApprovalPolicy::documentTypes(),
            fn (string $type): bool => ApprovalPolicy::supportsExtraLevel($type),
        ));

        $this->assertSame(['award_decision'], $laddered);
    }

    /**
     * Sel modenya ADA di layar hanya untuk baris itu. Sebelas baris yang dulu
     * mendapatkannya sekarang tidak punya kuncinya sama sekali di registri —
     * jadi bukan hanya tidak tergambar, melainkan juga tidak dapat ditulis.
     */
    public function test_the_screen_offers_a_mode_cell_on_exactly_one_row(): void
    {
        $group = $this->matrixGroup();

        $modeKeys = array_values(array_filter(
            array_column($group['settings'], 'key'),
            fn (string $key): bool => str_ends_with($key, '.mode'),
        ));
        $thirdKeys = array_values(array_filter(
            array_column($group['settings'], 'key'),
            fn (string $key): bool => str_ends_with($key, '.third_level_threshold'),
        ));

        $this->assertSame(['approvals.award_decision.mode'], $modeKeys);
        $this->assertSame(['approvals.award_decision.third_level_threshold'], $thirdKeys);

        // Barisnya tetap 28 dan ambangnya tetap ditawarkan di empat belas
        // baris: yang dicabut adalah modenya, bukan matriksnya.
        $this->assertCount(28, $group['matrix']);
        $thresholdKeys = array_filter(
            array_column($group['settings'], 'key'),
            fn (string $key): bool => str_ends_with($key, '.threshold_two_level'),
        );
        $this->assertCount(14, $thresholdKeys); // 28 - 13 tanpa nilai rupiah - 1 yang mengikuti SPK

        foreach ($group['matrix'] as $row) {
            $this->assertSame(
                $row['type'] === 'award_decision',
                $row['supports_extra_level'],
                "matrix row {$row['type']} disagrees with ApprovalPolicy::supportsExtraLevel",
            );
        }
    }

    /**
     * DAN SEBUAH BARIS SETELAN YANG SUDAH TERLANJUR TERTULIS TIDAK BERBUNYI.
     * Kunci itu tidak lagi dapat disunting lewat layar, tetapi ia bisa saja
     * sudah ada di basis data instalasi yang memasang F-1 sebelum putaran ini
     * — dan resolvernya harus membacanya sebagai single_director, bukan
     * sebagai sebuah jenjang yang tidak ada penegaknya.
     */
    public function test_a_mode_row_left_behind_in_the_database_resolves_to_a_single_approver(): void
    {
        // Ditulis lewat model, bukan lewat set(): kuncinya sudah TIDAK
        // editable, dan set() menolaknya — yang justru separuh dari perbaikan.
        $this->assertArrayNotHasKey('approvals.ar_invoice.mode', app(SettingService::class)->editableKeys());

        Setting::put('approvals.ar_invoice.threshold_two_level', 1, 'approval_matrix');
        Setting::put('approvals.ar_invoice.mode', ApprovalPolicy::MODE_EXTRA_LEVEL, 'approval_matrix');
        Setting::put('approvals.ar_invoice.third_level_threshold', 2, 'approval_matrix');
        app(SettingService::class)->flush();

        $policy = ApprovalPolicy::forType('ar_invoice');

        $this->assertSame(ApprovalPolicy::MODE_SINGLE_DIRECTOR, $policy->mode);
        $this->assertNull($policy->thirdLevelThreshold);
        $this->assertSame(1, $policy->levelsFor(2220000000.0));

        // Ambangnya tetap berlaku — yang dicabut adalah caranya, bukan
        // aturannya: Rp 2,22 miliar tetap menuntut seorang direktur.
        $this->assertTrue($policy->directorRequiredForSingleApproval(2220000000.0));
        $this->assertSame(1, (int) ($policy->stampFor(2220000000.0)['levels']));
        $this->assertTrue((bool) $policy->stampFor(2220000000.0)['director']);
    }
}
