<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseRequisition;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * T2.11 — kartu "Menunggu persetujuan Anda" dan tautan Tugas Saya hanya bagi
 * pemegang izin `.approve` mana pun.
 *
 * Diukur 2 Sep 2026 (HASIL-UJI §1, S5 › cards): kartu tergambar untuk 11 dari
 * 11 peran demo, 8 di antaranya tidak menyetujui apa pun. Klien
 * menyembunyikannya lewat SATU predikat (schema.js ANY_APPROVE: ada izin yang
 * berakhiran `.approve`), dan predikat itu jujur hanya selama server memang
 * menyaring kotak masuk dengan bentuk yang sama. Gerak kartunya diukur harness
 * S1 (warehouse vs direktur); yang dipaku di sini adalah tiga hal yang bisa
 * hanyut diam-diam tanpa build step:
 *
 *  - GET core/inbox kosong bagi pemegang izin tanpa satu pun `.approve`,
 *    sementara dokumen yang sama tampil bagi pemegang `<awalan>.approve`;
 *  - `.approve-director` saja tidak membuka kotak masuk — itulah mengapa
 *    ANY_APPROVE sengaja tidak menghitungnya;
 *  - schema.js (tautan), katalog widget P1-D (permintaan + kartu, satu
 *    gerbang) dan api.js (session.can memanggil predikat fungsi) masih memakai
 *    predikat itu.
 *
 * PUTARAN KEDUA VERIFIKASI F-1 menambahkan sisi keempat, dan ia adalah sisi
 * yang paling lama salah: gerbang klien membaca `user.permissions` dari
 * auth/me — getAllPermissions() milik Spatie, yang TIDAK melewati Gate::before
 * — jadi hak yang dipinjamkan sebuah delegasi tidak pernah sampai ke layar.
 * Seorang delegat murni karena itu tidak melihat tautan Tugas Saya dan tidak
 * melihat satu pun tombol Setujui, sementara ApprovalQueue::pending memanggil
 * grants() dan mengisi kotak masuknya. Yang dipaku di bawah adalah bahwa
 * kedua sisi kini memakai bentuk yang sama — hak pinjaman dihitung, TETAPI
 * hanya di pintu keputusan dokumen.
 */
class ApprovalInboxGateTest extends ErpTestCase
{
    public function test_the_inbox_is_empty_for_a_user_without_any_approve_permission(): void
    {
        $maker = $this->userWith('prc.create');
        // warehouse@nusantara.test's bundle (RoleSeeder): inv.* tanpa approve + prj.view.
        $storekeeper = $this->userWith('inv.view', 'inv.create', 'inv.update', 'inv.delete', 'prj.view');
        $approver = $this->userWith('prc.approve');
        $pr = $this->submittedPr($maker);

        $this->actingAs($storekeeper, 'sanctum')->getJson('/api/core/inbox')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);

        $this->actingAs($approver, 'sanctum')->getJson('/api/core/inbox')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', $pr->code);
    }

    public function test_a_director_signature_alone_does_not_open_the_inbox(): void
    {
        $maker = $this->userWith('prc.create');
        $director = $this->userWith('prc.approve-director');
        $this->submittedPr($maker);

        $this->actingAs($director, 'sanctum')->getJson('/api/core/inbox')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_the_spa_gates_the_link_the_request_and_the_card_with_one_predicate(): void
    {
        $schema = $this->spa('schema.js');
        $this->assertStringContainsString(
            'export const ANY_APPROVE = (held, lent = []) => '
                ."held.some((one) => one.endsWith('.approve')) || lent.length > 0;",
            $schema,
            'ANY_APPROVE harus mencerminkan penyaring ApprovalQueue::pending: "<awalan>.approve" yang '
                .'DIPEGANG atau yang DIPINJAM sebuah delegasi (grants()), tanpa .approve-director.',
        );
        $this->assertStringContainsString("{ label: 'Tugas Saya', route: 'tugas', perm: ANY_APPROVE }", $schema);

        /*
         * P1-D memindahkan gerbangnya, dan menjadikannya SATU.
         *
         * Sampai P1-C dashboard.js menyebut `session.can(ANY_APPROVE)` dua
         * kali — sekali untuk permintaan core/inbox, sekali untuk kartunya —
         * dan uji ini menghitung keduanya, karena satu tanpa yang lain berarti
         * kartu kosong atau permintaan sia-sia. Sejak P1-D kotak masuk adalah
         * widget: berkas `views/widgets/inbox.js` TIDAK DIIMPOR sama sekali
         * kecuali resolveLayout meloloskan izinnya, sehingga permintaan dan
         * kartunya tidak bisa lagi berselisih — keduanya di balik satu gerbang
         * yang dinyatakan katalog.
         *
         * Yang dipaku sekarang adalah rantai itu: katalog menuntut
         * '*.approve' untuk widget inbox, permOf menerjemahkannya ke predikat
         * ANY_APPROVE yang sama dengan sidebar, dan penyusunnya benar-benar
         * memakai resolveLayout untuk memutuskan apa yang dimuat.
         */
        $registry = $this->spa('views/widgets/registry.js');
        $this->assertStringContainsString(
            "module: 'ringkasan', perm: '*.approve', route: 'tugas',",
            $registry,
            'Widget kotak masuk tidak lagi bergerbang *.approve di katalog.',
        );
        $this->assertStringContainsString(
            "if (widget.perm === '*.approve') return ANY_APPROVE;",
            $registry,
            "permOf harus menerjemahkan '*.approve' ke predikat ANY_APPROVE yang sama dengan sidebar — bukan aturan kedua.",
        );

        $dashboard = $this->spa('views/dashboard.js');
        $this->assertStringContainsString('resolveLayout(stored, can)', $dashboard,
            'Penyusun dasbor tidak lagi memakai resolveLayout, jadi gerbang izin katalog tidak menentukan apa yang dimuat.');
        $this->assertSame(
            0,
            substr_count($dashboard, 'core/inbox'),
            'dashboard.js menyebut core/inbox lagi; permintaan itu milik widget-nya, di balik gerbang katalog.',
        );

        $api = $this->spa('api.js');
        $this->assertStringContainsString(
            "if (typeof permission === 'function') return permission(held, lent);",
            $api,
            'session.can harus memanggil predikat fungsi dengan daftar izin YANG DIPEGANG dan YANG DIPINJAM; '
                .'tanpa argumen kedua, seorang delegat murni tidak mendapat tautan Tugas Saya sama sekali.',
        );

        /*
         * DAN HANYA DI PINTU KEPUTUSAN DOKUMEN — bentuk yang sama dengan
         * ApprovalDelegations::DECISION_ROUTE di server.
         *
         * Gerbang klien yang meleburkan hak pinjaman ke dalam setiap
         * pemeriksaan izin akan menggambar tombol yang dijawab 403: izin
         * `<awalan>.approve` yang sama juga menggerbangi posting jurnal manual,
         * membuka kembali periode fiskal, mengaktifkan kontrak, menutup insiden
         * K3 dan menstempel submittal (15 rute, diukur 7 Sep 2026).
         */
        $this->assertStringContainsString(
            "/\\{id\\}\\/(approve|reject)\$/.test(String(action.path || ''))",
            $api,
            'session.isDecisionDoor harus mengenali pintu keputusan dari BENTUK jalurnya, sama seperti server.',
        );
        $this->assertStringContainsString(
            'return this.can(action.perm, this.isDecisionDoor(action));',
            $api,
            'session.canAct harus memberi hak pinjaman HANYA di pintu keputusan dokumen.',
        );
        $this->assertStringContainsString(
            '.filter((action) => session.canAct(action))',
            $this->spa('views/actions.js'),
            'Bilah aksi layar dokumen harus memakai canAct(); dengan can(action.perm) seorang delegat tidak '
                .'pernah melihat tombol Setujui, dan dengan can(perm, true) ia melihat sebelas tombol yang bukan haknya.',
        );

        /*
         * …dan layar yang MENULIS tombolnya sendiri ikut menghitung hak pinjaman.
         *
         * canAct() hanya menjangkau aksi yang lahir dari schema.js. Dua layar
         * merakit tombol Setujui/Tolak-nya dengan tangan dan memanggil
         * POST …/{id}/approve|reject langsung: Pembayaran (views/custom.js,
         * finance/payments) dan Baseline EVM (views/evm.js, projects/baselines).
         * Sampai verifikasi F-1 putaran 2 keduanya memakai session.can() polos,
         * jadi delegat murni — justru orang yang fiturnya ada untuknya — tidak
         * pernah melihat tombolnya sementara servernya menerima keputusannya
         * (diukur di Chromium 7 Sep 2026: pembayaran menunggu, tombol tidak ada).
         */
        foreach ([
            'views/custom.js' => ["session.can('fin.approve', true)", 3],
            'views/evm.js' => ["session.can('prj.approve', true)", 1],
        ] as $file => [$call, $times]) {
            $this->assertSame(
                $times,
                substr_count($this->spa($file), $call),
                "{$file} harus memanggil {$call} pada setiap pintu keputusan tulis-tangannya "
                    .'({id}/approve|reject); tanpa argumen kedua, hak pinjaman delegasi tidak dihitung.',
            );
        }
    }

    // -------------------------------------------------------------- fixtures

    private function userWith(string ...$permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);
        $user = User::query()->create([
            'name' => 'Pengguna '.substr(md5(implode('|', $permissions)), 0, 4),
            'email' => substr(md5(implode('|', $permissions).microtime()), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function submittedPr(User $submitter): PurchaseRequisition
    {
        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-12-31',
            'status' => 'draft',
            'purpose' => 'Uji gerbang kotak masuk',
            'requested_by' => $submitter->id,
        ]);
        $pr->submit($submitter);

        return $pr->fresh();
    }

    private function spa(string $file): string
    {
        return (string) file_get_contents(public_path('app/js/'.$file));
    }
}
