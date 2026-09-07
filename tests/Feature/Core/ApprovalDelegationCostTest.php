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
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $work();

            return array_values(array_filter(
                array_column(DB::getQueryLog(), 'query'),
                static fn (string $sql): bool => str_contains($sql, 'core_approval_delegations'),
            ));
        } finally {
            DB::disableQueryLog();
        }
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
