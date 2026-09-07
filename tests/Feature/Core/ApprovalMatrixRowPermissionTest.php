<?php

namespace Tests\Feature\Core;

use App\Models\User;
use InvalidArgumentException;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\ApprovalPolicy;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseOrder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * IZIN YANG DITUNTUT ADALAH IZIN BARISNYA (verifikasi F-1, 7 Sep 2026).
 *
 * Penjaga F-1 yang dikirim menerima izin *.approve-director MANA PUN untuk
 * baris mana pun. Terukur: pengguna dengan core.update + hr.approve-director
 * mengirim PUT /api/core/settings dengan
 * approvals.purchase_order.threshold_two_level = 10.000.000.000 dan menerima
 * HTTP 200; PurchaseOrder::directorApprovalThreshold() lalu membaca angka itu.
 * Direktur payroll menaikkan gerbang uang pengadaan seratus kali lipat.
 *
 * Docblock penjaganya menyatakan maksud yang tidak dikerjakannya: "orang yang
 * mengubah aturan persetujuan harus orang yang berdiri di dalam aturan itu".
 * Memegang hr.approve-director tidak menempatkan siapa pun di dalam aturan PO.
 */
class ApprovalMatrixRowPermissionTest extends ErpTestCase
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
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    public function test_a_payroll_director_cannot_move_the_procurement_money_gate(): void
    {
        $actor = $this->userHolding('hrd@t.local', 'core.update', 'core.view', 'hr.approve-director');

        $response = $this->actingAs($actor)->putJson('/api/core/settings', [
            'settings' => ['approvals.purchase_order.threshold_two_level' => 10_000_000_000],
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertStringContainsString(
            'prc.approve-director',
            (string) ($errors['settings.approvals.purchase_order.threshold_two_level'][0] ?? ''),
        );

        app(SettingService::class)->flush();
        $this->assertSame(100000000.0, ApprovalPolicy::forType('purchase_order')->threshold);
        $this->assertSame(100000000.0, (float) (new PurchaseOrder)->directorApprovalThreshold());
    }

    public function test_the_director_of_that_module_still_may(): void
    {
        $actor = $this->userHolding('direktur@t.local', 'core.update', 'core.view', 'prc.approve-director');

        $this->actingAs($actor)->putJson('/api/core/settings', [
            'settings' => ['approvals.purchase_order.threshold_two_level' => 250_000_000],
        ])->assertOk();

        app(SettingService::class)->flush();
        $this->assertSame(250000000.0, ApprovalPolicy::forType('purchase_order')->threshold);
    }

    /**
     * SATU FORMULIR, DUA BARIS, SATU PENOLAKAN. Yang ditolak adalah baris yang
     * izinnya tidak dipegang — bukan seluruh simpanan, dan bukan tidak satu
     * pun. 422 per-parameter memang untuk ini.
     */
    public function test_a_save_carrying_two_rows_refuses_only_the_row_the_editor_does_not_hold(): void
    {
        $actor = $this->userHolding('direktur-hr@t.local', 'core.update', 'core.view', 'hr.approve-director');

        $response = $this->actingAs($actor)->putJson('/api/core/settings', [
            'settings' => [
                'approvals.payroll_run.threshold_two_level' => 500_000_000,
                'approvals.purchase_order.threshold_two_level' => 10_000_000_000,
            ],
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');

        $this->assertArrayHasKey('settings.approvals.purchase_order.threshold_two_level', $errors);
        $this->assertArrayNotHasKey('settings.approvals.payroll_run.threshold_two_level', $errors);
    }

    /**
     * Penjaganya ada di service juga, jadi memanggil SettingService langsung
     * bukan jalan memutar.
     */
    public function test_the_service_enforces_the_same_row_rule(): void
    {
        $actor = $this->userHolding('hrd@t.local', 'core.update', 'hr.approve-director');
        $this->actingAs($actor);

        try {
            app(SettingService::class)->set('approvals.purchase_order.threshold_two_level', 10_000_000_000);
            $this->fail('the service must refuse a row whose director permission the actor does not hold');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('prc.approve-director', $e->getMessage());
        }

        // Barisnya sendiri tetap boleh.
        app(SettingService::class)->set('approvals.payroll_run.threshold_two_level', 500_000_000);
        $this->assertSame(500000000.0, ApprovalPolicy::forType('payroll_run')->threshold);
    }

    /**
     * TIGA KUNCI TIDAK BERDIRI DI SATU MODUL — umur antrean, pemisahan tugas
     * dan plafon setujui massal berlaku untuk kesepuluh awalan sekaligus, jadi
     * tuntutannya tetap "salah satu izin direktur". Menuntut kesepuluhnya
     * berarti tidak ada yang bisa mengubahnya.
     */
    public function test_the_three_cross_cutting_keys_still_accept_any_director_permission(): void
    {
        $this->assertNull(ApprovalPolicy::directorPermissionForKey('approvals.aging_days'));
        $this->assertNull(ApprovalPolicy::directorPermissionForKey('approvals.segregation_of_duties'));
        $this->assertNull(ApprovalPolicy::directorPermissionForKey('approvals.batch_cap'));

        $actor = $this->userHolding('hrd@t.local', 'core.update', 'hr.approve-director');
        $this->actingAs($actor);

        app(SettingService::class)->set('approvals.aging_days', 9);
        $this->assertSame(9, (int) app(SettingService::class)->get('approvals.aging_days'));
    }

    /** Dan tiap baris jenis dokumen memetakan ke izin awalannya sendiri. */
    public function test_every_row_key_maps_to_the_permission_of_its_own_module(): void
    {
        foreach (ApprovalPolicy::documentTypes() as $type) {
            $keys = ApprovalPolicy::keysFor($type);
            $expected = ApprovalPolicy::forType($type)->directorPermission();

            foreach ($keys as $key) {
                $this->assertSame(
                    $expected,
                    ApprovalPolicy::directorPermissionForKey($key),
                    "key {$key} does not demand the permission its own row names",
                );
            }
        }
    }
}
