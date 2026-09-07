<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ApprovalDelegation;
use Modules\Core\Support\ApprovalDelegations;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * APA YANG DIBAYAR SETIAP PEMERIKSAAN IZIN (verifikasi F-1, 7 Sep 2026).
 *
 * Laporan paket menyebut ini "tidak terukur", lalu menebak: "satu SELECT per
 * permintaan untuk yang punya delegasi, nol kueri untuk yang tidak". Tebakannya
 * salah di kedua arahnya. Angka yang benar, diukur di sesi ini pada SQLite:
 *
 *   pemegang izin ASLINYA, pemeriksaan approve  0 kueri (Gate::before Spatie
 *       menjawab lebih dulu; callback delegasi tidak pernah berjalan);
 *   pemeriksaan izin DI LUAR pintu keputusan dokumen  0 kueri sejak saringan
 *       rute f1-perm-01 — sebelumnya setiap pemeriksaan approve yang GAGAL
 *       membayar dua, dan pemeriksaan yang gagal adalah persis yang dilakukan
 *       sebuah layar penuh tombol bergerbang izin;
 *   kotak masuk (satu-satunya pemanggil grants() yang disengaja di luar pintu
 *       itu)                                       2 kueri untuk seluruh
 *       permintaan, bukan per jenis dokumen atau per baris;
 *   yang benar-benar memakai delegasi                DUA kueri per unit kerja
 *       (Schema::hasTable — di MySQL sebuah kueri information_schema — plus
 *       satu SELECT), bukan dua per pemeriksaan.
 *
 * Uji ini yang menjaganya: regresi yang menjadikan memo-nya per-pemeriksaan
 * alih-alih per-unit-kerja akan memerahkannya.
 *
 * PUTARAN KEDUA — DAN ANGKA-ANGKA DI ATAS MENGUKUR TABEL YANG SALAH.
 * Semuanya menyaring log kueri pada kata "core_approval_delegations", dan harga
 * sebuah delegasi hampir seluruhnya dibayar di tabel LAIN: users,
 * model_has_permissions, model_has_roles. Diukur pada GET /api/core/inbox yang
 * sama (SQLite, 7 Sep 2026): 8 kueri untuk pembaca tanpa delegasi, 46 untuk
 * pembaca yang sama dengan delegasi lingkup penuh — dan kedua angka itu
 * dilaporkan "2" oleh saringan lama. Uji terakhir di bawah mengukur SELURUH
 * lognya.
 */
class ApprovalDelegationCostTest extends ErpTestCase
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

    /** @return list<string> */
    private function delegationQueriesDuring(callable $work): array
    {
        return array_values(array_filter(
            $this->queriesDuring($work),
            static fn (string $sql): bool => str_contains($sql, 'core_approval_delegations'),
        ));
    }

    /**
     * SETIAP kueri, bukan hanya yang menyebut tabel delegasi.
     *
     * Saringan di atas adalah persis sebab temuan putaran kedua lolos: harga
     * sebuah delegasi hampir seluruhnya dibayar di tabel LAIN — users,
     * model_has_permissions, model_has_roles — dan sebuah pengukuran yang
     * hanya melihat core_approval_delegations melaporkan "dua" untuk sebuah
     * permintaan yang sesungguhnya menjalankan 46.
     *
     * @return list<string>
     */
    private function queriesDuring(callable $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $work();

            return array_values(array_column(DB::getQueryLog(), 'query'));
        } finally {
            DB::disableQueryLog();
        }
    }

    /**
     * Kutipan identitas dibuang, jadi satu pola cocok di SQLite ("users") dan
     * di MySQL (`users`) — kedua kaki gerbang menjalankan uji yang sama.
     *
     * @param  list<string>  $queries
     */
    private function countMatching(array $queries, string $needle): int
    {
        return count(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains(str_replace(['`', '"'], '', $sql), $needle),
        ));
    }

    /**
     * PEMEGANG ASLINYA MEMBAYAR NOL. Gate::before Spatie menjawab lebih dulu,
     * jadi callback delegasi tidak pernah berjalan.
     */
    public function test_a_native_holder_pays_nothing(): void
    {
        $user = $this->userHolding('penyetuju@t.local', 'est.approve');
        $user->refresh();
        $user->can('est.approve');

        $queries = $this->delegationQueriesDuring(function () use ($user): void {
            $user->can('est.approve');
            $user->can('est.approve');
        });

        $this->assertSame([], $queries);
    }

    /**
     * KOTAK MASUK MEMBAYAR DUA, SEKALI UNTUK SELURUH PERMINTAAN — bukan sekali
     * per jenis dokumen (28) dan bukan sekali per baris antrean. Ia satu-satunya
     * pembaca yang memanggil grants() di luar pintu keputusan, dan ia melakukannya
     * dengan sengaja: antrean adalah bacaan.
     */
    public function test_the_inbox_asks_the_delegation_table_once_for_the_whole_request(): void
    {
        $user = $this->userHolding('pembaca@t.local', 'est.view', 'core.view');
        $this->actingAs($user);

        $queries = $this->delegationQueriesDuring(function (): void {
            $this->getJson('/api/core/inbox')->assertOk();
        });

        // DUA, bukan nol: kotak masuk memanggil grants() langsung (lihat
        // ApprovalQueue), dan panggilan pertama membayar Schema::hasTable plus
        // satu SELECT. Yang dijaga adalah bahwa keduanya dibayar SEKALI untuk
        // seluruh permintaan — bukan sekali per jenis dokumen (28 jenis) dan
        // bukan sekali per baris antrean.
        $this->assertCount(2, $queries, implode(' | ', $queries));
    }

    /**
     * DAN PEMBERINYA DIMUAT SEKALI, bukan sekali per awalan modul.
     *
     * Ini temuan putaran kedua verifikasi F-1, dan uji di atas tidak dapat
     * melihatnya: harga sebuah delegasi hampir seluruhnya dibayar di tabel
     * LAIN. giverHoldsNatively() dulu memanggil User::query()->find($giverId)
     * pada setiap (pemberi, ability) yang belum dimemo — sebuah instance BARU
     * setiap kali, jadi Spatie memuat ulang izin dan peran instance itu juga.
     * ApprovalQueue::pending menanyakan 10 awalan modul yang berbeda, jadi
     * seorang delegat membayar tiga kueri itu sepuluh kali.
     *
     * DIUKUR pada permintaan GET /api/core/inbox yang sama, SQLite, 7 Sep 2026:
     *
     *              pembaca tanpa delegasi   delegat (lingkup penuh)
     *   sebelum              8                        46
     *   sesudah              8                        26
     *
     * Selisihnya, per pernyataan: 10 x `select * from users where id = ?`
     * menjadi 1, 10 x join model_has_permissions menjadi 1, 5 x join
     * model_has_roles menjadi 1 — 20 kueri.
     *
     * SISA 18-nya BUKAN ONGKOS DELEGASI melainkan PEKERJAANNYA: seorang
     * delegat benar-benar boleh memutuskan dokumen lima modul, jadi antreannya
     * memindai dua belas tabel dokumen yang tidak dipindai pembaca tanpa
     * delegasi. Itu kueri yang harus ada; sepuluh pemuatan ulang orang yang
     * sama tidak.
     *
     * Yang dipaku uji ini adalah ketiga hitungan pernyataan di bawah: sebuah
     * regresi yang mengembalikan pemuatan per-ability memerahkannya.
     */
    public function test_the_giver_is_loaded_once_for_the_whole_inbox_not_once_per_module(): void
    {
        $giver = $this->userHolding(
            'pemberi@t.local',
            'est.approve', 'prc.approve', 'fin.approve', 'hr.approve', 'crm.approve',
        );
        $delegate = $this->userHolding('delegat@t.local', 'est.view', 'core.view');

        ApprovalDelegation::query()->create([
            'giver_user_id' => $giver->id,
            'delegate_user_id' => $delegate->id,
            'scope' => null,
            'starts_at' => now()->subDay()->toDateString(),
        ]);
        ApprovalDelegations::flushMemo();

        $this->actingAs($delegate->fresh());

        $queries = $this->queriesDuring(function (): void {
            $this->getJson('/api/core/inbox')->assertOk();
        });

        $userLoads = $this->countMatching($queries, 'from users where users.id = ?');
        $permissionLoads = $this->countMatching($queries, 'from permissions inner join model_has_permissions');
        $roleLoads = $this->countMatching($queries, 'from roles inner join model_has_roles');

        $this->assertSame(1, $userLoads, 'pemberi delegasi dimuat '.$userLoads.' kali: '.implode(' | ', $queries));
        // DUA, bukan satu: satu untuk pembacanya sendiri (Spatie, saat rutenya
        // memeriksa izin) dan satu untuk pemberinya. Sepuluh adalah cacatnya.
        $this->assertSame(2, $permissionLoads, 'izin dimuat '.$permissionLoads.' kali');
        $this->assertSame(2, $roleLoads, 'peran dimuat '.$roleLoads.' kali');

        // Dan pagarnya secara keseluruhan: seluruh permintaan, bukan hanya
        // ketiga pernyataan di atas. 46 sebelum perbaikan, 26 sesudah.
        $this->assertLessThanOrEqual(30, count($queries), count($queries).' kueri: '.implode(' | ', $queries));
    }

    /**
     * DUA KUERI PER UNIT KERJA — bukan dua per pemeriksaan. Memo-nya di-bind
     * scoped(), jadi pemeriksaan kedua dan seterusnya gratis, termasuk untuk
     * awalan dan ability yang berbeda.
     *
     * Diukur di sesi ini pada SQLite: Schema::hasTable (di MySQL sebuah kueri
     * information_schema) plus satu SELECT ke core_approval_delegations.
     */
    public function test_two_queries_per_unit_of_work_not_two_per_check(): void
    {
        $giver = $this->userHolding('sari@t.local', 'est.approve');
        $delegate = $this->userHolding('budi@t.local', 'est.view');

        ApprovalDelegation::query()->create([
            'giver_user_id' => $giver->id,
            'delegate_user_id' => $delegate->id,
            'scope' => null,
            'starts_at' => now()->subDay()->toDateString(),
        ]);
        ApprovalDelegations::flushMemo();

        $fresh = $delegate->fresh();

        $queries = $this->delegationQueriesDuring(function () use ($fresh): void {
            ApprovalDelegations::grants($fresh, 'est.approve');
            ApprovalDelegations::grants($fresh, 'est.approve');
            ApprovalDelegations::grants($fresh, 'est.approve-director');
            ApprovalDelegations::grants($fresh, 'prc.approve');
        });

        $this->assertCount(2, $queries, implode(' | ', $queries));
    }
}
