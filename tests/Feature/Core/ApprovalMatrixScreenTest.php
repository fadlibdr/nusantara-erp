<?php

namespace Tests\Feature\Core;

use App\Models\User;
use InvalidArgumentException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\AuditLog;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\ApprovalPolicy;
use Modules\Estimation\Models\Boq;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * MATRIKS PERSETUJUAN dari layar (F-1 / T1.1) — dan dua penjaganya.
 *
 * Yang dikirim paket ini adalah SEBUAH LAYAR, bukan sebuah kebijakan baru:
 * setiap baris membawa nilai yang mengatur jenis dokumen itu HARI INI, jadi
 * memasangnya tidak mengubah satu pun keputusan. Uji pertama di bawah adalah
 * uji itu, dan ia adalah uji terpenting dalam paket ini — sebuah perubahan
 * diam-diam atas siapa boleh menyetujui apa adalah cacat uang.
 *
 * Dua penjaga:
 *   core.update SAJA TIDAK CUKUP. Mengubah approvals.* menuntut pula sebuah
 *       izin *.approve-director. core.update juga membuka format penomoran dan
 *       asumsi arus kas; menurunkan ambang PO adalah keputusan uang, dan orang
 *       yang mengubah aturan persetujuan harus berdiri di dalam aturan itu.
 *   SETIAP PERUBAHAN DIAUDIT dengan nilai efektif dari → ke.
 */
class ApprovalMatrixScreenTest extends ErpTestCase
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

    private function matrixGroup(): array
    {
        $groups = app(SettingService::class)->overview();

        foreach ($groups as $group) {
            if ($group['key'] === 'approval_matrix') {
                return $group;
            }
        }

        $this->fail('the settings registry has no approval_matrix group');
    }

    // ------------------------------------------- nilai hari ini sebagai bawaan

    /**
     * DUA PULUH DELAPAN BARIS, dan setiap satunya membawa aturan yang berlaku
     * hari ini. Angkanya ditulis apa adanya: sebuah uji yang membaca nilainya
     * dari sumber yang sama dengan kodenya akan tetap hijau ketika keduanya
     * bergeser bersama, dan yang dijaga di sini justru pergeseran itu.
     */
    public function test_the_matrix_ships_with_the_values_that_govern_each_type_today(): void
    {
        $rows = $this->matrixGroup()['matrix'];
        $this->assertCount(28, $rows);

        $policies = ApprovalPolicy::all();

        $this->assertSame(100000000.0, $policies['purchase_order']->threshold);
        $this->assertSame(200000000.0, $policies['subcontract']->threshold);
        $this->assertSame(200000000.0, $policies['subcontract_addendum']->threshold);
        $this->assertSame(100000000.0, $policies['award_decision']->threshold);
        $this->assertSame(1000000000.0, $policies['award_decision']->thirdLevelThreshold);
        $this->assertSame(ApprovalPolicy::MODE_EXTRA_LEVEL, $policies['award_decision']->mode);

        foreach ($policies as $type => $policy) {
            if (in_array($type, ['purchase_order', 'subcontract', 'subcontract_addendum', 'award_decision'], true)) {
                continue;
            }

            $this->assertNull($policy->threshold, "[{$type}] must ship with no threshold");
        }
    }

    /**
     * "Tanpa ambang" adalah ATURAN, bukan Rp 0. Sebuah ambang nol berarti
     * setiap dokumen menuntut direktur — kebalikan persis keadaannya, dan
     * satu-satunya angka yang bisa mengubah aturan uang tanpa seorang pun
     * mengetiknya.
     */
    public function test_no_threshold_is_null_and_never_zero(): void
    {
        foreach ($this->matrixGroup()['settings'] as $setting) {
            if (($setting['column'] ?? null) !== 'threshold') {
                continue;
            }

            $this->assertNotSame(0, $setting['default'], "[{$setting['key']}] ships as a fabricated zero");
            $this->assertNotSame(0.0, $setting['default'], "[{$setting['key']}] ships as a fabricated zero");
        }

        foreach (ApprovalPolicy::all() as $policy) {
            $this->assertNotSame(0.0, $policy->threshold);
        }
    }

    /**
     * Tiga belas jenis tanpa nilai rupiah TIDAK mendapat kotak isian ambang.
     * Menawarkan satu di sana berarti menawarkan kendali yang tidak akan
     * pernah berbunyi — kegagalan yang sama persis dengan
     * needs_director_approval yang dulu dicap, ditampilkan, dan tidak dibaca
     * siapa pun saat menyetujui.
     */
    public function test_a_type_with_no_amount_gets_no_threshold_field(): void
    {
        $group = $this->matrixGroup();
        $keys = array_column($group['settings'], 'key');
        $amountless = 0;

        foreach ($group['matrix'] as $row) {
            if ($row['has_amount']) {
                continue;
            }

            $amountless++;
            $this->assertNotContains(
                $row['keys']['threshold'],
                $keys,
                "[{$row['type']}] has no amount column, so a threshold there could never fire",
            );
        }

        $this->assertSame(13, $amountless, 'measured from the schema, 7 Sep 2026');
    }

    /** PO/SPK/addendum: ambang bisa diedit, mode TIDAK — modulnya yang menegakkan. */
    public function test_the_three_types_with_their_own_gate_offer_a_threshold_but_no_mode(): void
    {
        $group = $this->matrixGroup();
        $keys = array_column($group['settings'], 'key');

        foreach (['purchase_order', 'subcontract'] as $type) {
            $this->assertTrue(ApprovalPolicy::modeIsLocked($type));
            $this->assertContains(ApprovalPolicy::keysFor($type)['threshold'], $keys);
            $this->assertNotContains(ApprovalPolicy::keysFor($type)['mode'], $keys);
        }

        // Addendum tidak punya kunci sendiri sama sekali: barisnya memantul.
        $addendum = collect($group['matrix'])->firstWhere('type', 'subcontract_addendum');
        $this->assertSame('subcontract', $addendum['follows']);
        $this->assertSame('SPK subkontraktor', $addendum['follows_label']);
    }

    /** Setiap baris menamai izin direktur yang akan dituntut ambangnya. */
    public function test_every_row_names_the_director_permission_its_threshold_would_demand(): void
    {
        foreach ($this->matrixGroup()['matrix'] as $row) {
            $this->assertSame("{$row['prefix']}.approve-director", $row['director_permission']);
            $this->assertContains($row['director_permission'], PermissionSeeder::directorApprovals());
        }
    }

    // ------------------------------------------------------------- penjaga

    public function test_core_update_alone_cannot_change_an_approval_rule(): void
    {
        $actor = $this->userHolding('admin-biasa@t.local', 'core.update', 'core.view');

        $response = $this->actingAs($actor)->putJson('/api/core/settings', [
            'settings' => ['approvals.purchase_order.threshold_two_level' => 5_000_000_000],
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'approve-director',
            json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE),
        );

        $this->assertSame(100000000.0, ApprovalPolicy::forType('purchase_order')->threshold);
    }

    public function test_a_director_with_core_update_may_change_it(): void
    {
        $actor = $this->userHolding('direktur@t.local', 'core.update', 'core.view', 'prc.approve-director');

        $this->actingAs($actor)->putJson('/api/core/settings', [
            'settings' => ['approvals.purchase_order.threshold_two_level' => 250_000_000],
        ])->assertOk();

        app(SettingService::class)->flush();
        $this->assertSame(250000000.0, ApprovalPolicy::forType('purchase_order')->threshold);
    }

    /**
     * Penjaganya juga ada di service, jadi memanggil SettingService langsung
     * bukan jalan memutar. (Kunci yang bukan approvals.* tidak tersentuh.)
     */
    public function test_the_service_enforces_the_same_rule_as_the_endpoint(): void
    {
        $actor = $this->userHolding('admin-biasa@t.local', 'core.update');
        $this->actingAs($actor);

        try {
            app(SettingService::class)->set('approvals.aging_days', 9);
            $this->fail('the service must refuse an approval rule change without a director permission');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('approve-director', $e->getMessage());
        }

        // Parameter lain tetap boleh — penjaganya hanya approvals.*.
        app(SettingService::class)->set('tax.ppn_rate', 12);
        $this->assertSame(12.0, (float) app(SettingService::class)->get('tax.ppn_rate'));
    }

    /**
     * Seeder, migrasi dan perintah konsol menulis tanpa aktor. Menolak mereka
     * berarti instalasi baru tidak bisa diseed; yang dijaga adalah ORANG, dan
     * orang selalu punya sesi.
     */
    public function test_a_console_write_with_no_authenticated_user_is_allowed(): void
    {
        app(SettingService::class)->set('approvals.aging_days', 9);

        $this->assertSame(9, (int) app(SettingService::class)->get('approvals.aging_days'));
    }

    // --------------------------------------------------------------- audit

    public function test_every_approval_rule_change_is_audited_from_and_to(): void
    {
        $actor = $this->userHolding('direktur@t.local', 'core.update', 'prc.approve-director');
        $this->actingAs($actor);

        app(SettingService::class)->set('approvals.purchase_order.threshold_two_level', 250_000_000);

        $log = AuditLog::query()->where('auditable_label', 'approvals.purchase_order.threshold_two_level')->latest('id')->first();

        $this->assertNotNull($log, 'a rule change with no audit line is a rule change nobody can find');
        $this->assertSame($actor->id, (int) $log->user_id);
        $this->assertSame($actor->name, $log->user_name);
        $this->assertSame(100000000, (int) $log->changes['effective']['from']);
        $this->assertSame(250000000, (int) $log->changes['effective']['to']);
        $this->assertNotNull($log->created_at);
    }

    /** Reset ke bawaan juga dicatat, dan "ke" adalah nilai pabriknya — bukan "dihapus". */
    public function test_resetting_a_rule_is_audited_with_the_shipped_default_as_the_new_value(): void
    {
        $actor = $this->userHolding('direktur@t.local', 'core.update', 'prc.approve-director');
        $this->actingAs($actor);

        $settings = app(SettingService::class);
        $settings->set('approvals.purchase_order.threshold_two_level', 250_000_000);
        $settings->set('approvals.purchase_order.threshold_two_level', null);

        $log = AuditLog::query()->where('auditable_label', 'approvals.purchase_order.threshold_two_level')->latest('id')->first();

        $this->assertSame(250000000, (int) $log->changes['effective']['from']);
        $this->assertSame(100000000, (int) $log->changes['effective']['to']);
    }

    /** Menyimpan nilai yang sama tidak menulis baris audit: log yang berisik berhenti dibaca. */
    public function test_writing_the_same_value_again_records_nothing(): void
    {
        $actor = $this->userHolding('direktur@t.local', 'core.update', 'prc.approve-director');
        $this->actingAs($actor);

        app(SettingService::class)->set('approvals.purchase_order.threshold_two_level', 100_000_000);

        $this->assertSame(
            0,
            AuditLog::query()
                ->where('auditable_label', 'approvals.purchase_order.threshold_two_level')
                ->where('event', 'updated')
                ->count(),
        );
    }

    // --------------------------------------------------- setujui massal (T1.6)

    public function test_bulk_approve_ships_off(): void
    {
        $this->assertNull(config('erp.approvals.batch_cap'));

        $user = $this->userHolding('penyetuju@t.local', 'est.approve');

        $this->assertNull(
            $this->actingAs($user)->getJson('/api/core/inbox')->assertOk()->json('meta.batch_cap'),
            'the inbox must not advertise a cap until the owner sets one',
        );
    }

    public function test_the_inbox_reports_the_cap_the_owner_set(): void
    {
        $director = $this->userHolding('direktur@t.local', 'core.update', 'prc.approve-director');
        $this->actingAs($director);
        app(SettingService::class)->set('approvals.batch_cap', 10);

        $user = $this->userHolding('penyetuju@t.local', 'est.approve');

        $this->assertSame(
            10,
            $this->actingAs($user)->getJson('/api/core/inbox')->assertOk()->json('meta.batch_cap'),
        );
    }

    /**
     * Nol tidak dapat disimpan sama sekali (min 1) — MEMATIKAN fitur ini
     * dilakukan dengan MENGOSONGKAN, bukan dengan mengetik nol, dan layar
     * mengatakannya. Dan seandainya sebuah instalasi lama menyimpan nol lewat
     * jalur lain, kotak masuk membacanya sebagai MATI: seorang operator yang
     * mengetik 0 berarti "jangan", dan menafsirkannya sebagai "tanpa batas"
     * adalah cara terburuk untuk salah membaca sebuah angka.
     */
    public function test_a_cap_of_zero_is_refused_and_would_read_as_off(): void
    {
        $director = $this->userHolding('direktur@t.local', 'core.update', 'prc.approve-director');
        $this->actingAs($director);

        try {
            app(SettingService::class)->set('approvals.batch_cap', 0);
            $this->fail('a cap of zero must be refused, not stored');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('approvals.batch_cap', $e->getMessage());
        }

        // Baris yang terlanjur ada dari jalur lain: dibaca sebagai mati.
        config(['erp.approvals.batch_cap' => 0]);
        $user = $this->userHolding('penyetuju@t.local', 'est.approve');

        $this->assertNull($this->actingAs($user)->getJson('/api/core/inbox')->assertOk()->json('meta.batch_cap'));
    }

    /**
     * TIDAK ADA ENDPOINT SERVER BARU untuk setujui massal. Loopnya di klien
     * dan memanggil endpoint approve tiap modul, jadi maker-checker, ambang
     * direktur, jurnal dan pemberitahuan berjalan persis seperti biasa. Baris
     * ini memaku ketiadaan itu: sebuah endpoint massal yang muncul kemudian
     * akan melewati semuanya sekaligus.
     */
    public function test_there_is_no_server_side_bulk_approve_endpoint(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression(
                '#(bulk|batch|mass).*approve|approve.*(bulk|batch|mass)#i',
                $route->uri(),
                'bulk approve must stay a client loop over each module\'s own endpoint',
            );
        }
    }

    /** Kotak masuk menamai endpoint approve tiap baris, atau null bila memang tidak ada. */
    public function test_the_inbox_names_the_module_endpoint_for_each_row(): void
    {
        $maker = $this->userHolding('drafter@t.local', 'est.create');
        $approver = $this->userHolding('penyetuju@t.local', 'est.approve', 'est.view');

        $boq = Boq::query()->create([
            'title' => 'RAB Struktur',
            'total' => 10_000_000,
            'status' => DocumentStatus::Draft,
        ]);
        $boq->submit($maker);

        $row = collect($this->actingAs($approver)->getJson('/api/core/inbox')->assertOk()->json('data'))
            ->firstWhere('code', $boq->fresh()->code);

        $this->assertNotNull($row);
        $this->assertSame("estimation/boqs/{$boq->id}/approve", $row['approve_url']);
    }
}
