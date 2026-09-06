<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Endpoint Laporan Bebas dari luar (Fase 1 / P1-F).
 *
 * Temuan verifikasi P1-F: setiap uji paket ini memanggil ReportRunner dan
 * ReportDefinition SECARA LANGSUNG, dan tidak satu pun pernah memanggil
 * `POST core/reports/run` — sehingga 403 yang menyebut izinnya, amplop
 * `meta.limits`, dan pilihan 422-versus-403 tidak pernah dijalankan sekali pun.
 * Berkas ini menutup lubang itu.
 */
class ReportEndpointTest extends ErpTestCase
{
    private function userWith(string $role, array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roleModel = Role::findOrCreate($role, 'web');
        $roleModel->syncPermissions($permissions);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pengguna '.$role,
            'email' => $role.'-'.substr(md5(microtime()), 0, 6).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($roleModel);

        return $user;
    }

    private function definition(): array
    {
        return [
            'resource' => 'finance/project-costs',
            'mode' => 'group',
            'row' => ['column' => 'cost_category'],
            'measure' => ['agg' => 'sum', 'column' => 'amount'],
        ];
    }

    public function test_the_catalogue_announces_its_ceilings_and_filters_itself(): void
    {
        $this->actingAs($this->userWith('finance', ['fin.view']), 'sanctum');

        $response = $this->getJson('/api/core/reports/resources')->assertOk();

        // Plafon DIUMUMKAN — SPA membacanya, tidak menghafalnya.
        $response->assertJsonPath('meta.limits.rows', 5000)
            ->assertJsonPath('meta.limits.groups', 200);

        $keys = array_column($response->json('data'), 'key');
        $this->assertContains('finance/project-costs', $keys);
        $this->assertNotContains('hr/employees', $keys);
    }

    public function test_a_report_runs_and_announces_one_query(): void
    {
        $this->actingAs($this->userWith('finance', ['fin.view']), 'sanctum');

        DB::table('fin_project_costs')->insert([
            'project_id' => 1, 'cost_date' => '2026-01-10', 'cost_category' => 'material',
            'reference_type' => 'manual', 'description' => 'uji', 'amount' => 1500000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/core/reports/run', $this->definition())->assertOk();

        $response->assertJsonPath('meta.queries', 1)
            ->assertJsonPath('meta.limits.groups', 200)
            ->assertJsonPath('data.mode', 'group')
            ->assertJsonPath('data.resource_label', 'Biaya Proyek')
            ->assertJsonPath('data.rows.0.key', 'material')
            // JSON menuliskan float yang nilainya bulat tanpa titik desimal
            // (1500000.0 → 1500000); yang penting adalah ANGKAnya, dan bahwa
            // ia bukan null — pembedaan kosong/nol tetap utuh di atasnya.
            ->assertJsonPath('data.rows.0.cells.0', fn ($v) => $v !== null && (float) $v === 1500000.0)
            ->assertJsonPath('data.rows.0.counts.0', 1);

        // Deskriptor dikirim supaya SPA melabeli dengan fungsi layar daftarnya.
        $this->assertSame('costCategory', $response->json('data.descriptors.row.enum'));
    }

    /** 403 MENYEBUT izin yang kurang — bukan 404, bukan kalimat umum. */
    public function test_a_resource_the_caller_may_not_read_is_refused_by_naming_the_permission(): void
    {
        $this->actingAs($this->userWith('warehouse', ['inv.view']), 'sanctum');

        $this->postJson('/api/core/reports/run', $this->definition())
            ->assertStatus(403)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'fin.view')
                && str_contains((string) $m, 'finance/project-costs'));
    }

    /** Definisi yang tidak sah 422 dengan kalimat yang menyebut kuncinya. */
    public function test_an_unknown_column_is_refused_naming_the_available_ones(): void
    {
        $this->actingAs($this->userWith('finance', ['fin.view']), 'sanctum');

        $this->postJson('/api/core/reports/run', [
            'resource' => 'finance/project-costs', 'mode' => 'group',
            'row' => ['column' => 'kolom-karangan'], 'measure' => ['agg' => 'count'],
        ])->assertStatus(422)
            ->assertJsonPath('errors.definition.0', fn ($m) => str_contains((string) $m, 'kolom-karangan')
                && str_contains((string) $m, 'cost_category'));
    }

    /**
     * Separuh KEDUA aturan degradasi registri: tabel yang belum ada menjawab
     * kalimat, bukan 500.
     *
     * Temuan verifikasi P1-F: hanya katalog yang memeriksa keberadaan tabel;
     * jalankan tidak, sehingga laporan tersimpan atas modul yang belum
     * termigrasi meledak alih-alih mengatakannya.
     */
    public function test_a_resource_whose_table_is_missing_is_refused_not_crashed(): void
    {
        $this->actingAs($this->userWith('finance', ['fin.view']), 'sanctum');

        Schema::drop('fin_project_costs');

        $this->postJson('/api/core/reports/run', $this->definition())
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'belum terpasang'));
    }

    /**
     * COUNT menjawab banyak BARIS, jadi deskriptornya bukan tipe kolomnya.
     *
     * `{agg: count, column: amount}` mewarisi type 'currency' dan layar
     * memformat hitungan dua baris sebagai 'Rp 2' — sementara
     * `ReportRunner::scaleOf()` sudah mengecualikan count sejak awal
     * (verifikasi kedua P1-F). Tidak bisa dibuat dari layar v1, bisa dari API
     * dan dari laporan tersimpan yang dibagikan.
     */
    public function test_a_count_over_a_column_is_described_as_a_row_count_not_as_money(): void
    {
        $this->actingAs($this->userWith('finance', ['fin.view']), 'sanctum');

        // Prasyarat: kolomnya memang uang bila yang diminta SUM.
        $this->postJson('/api/core/reports/run', $this->definition())
            ->assertOk()
            ->assertJsonPath('data.descriptors.measure.type', 'currency');

        $this->postJson('/api/core/reports/run', [
            'resource' => 'finance/project-costs', 'mode' => 'group',
            'row' => ['column' => 'cost_category'],
            'measure' => ['agg' => 'count', 'column' => 'amount'],
        ])
            ->assertOk()
            ->assertJsonPath('data.descriptors.measure.type', 'number')
            ->assertJsonPath('data.descriptors.measure.label', 'Banyak baris — Jumlah')
            ->assertJsonPath('data.descriptors.measure.enum', null)
            ->assertJsonPath('data.descriptors.measure.lookup', null);

        // COUNT tanpa kolom tetap seperti semula.
        $this->postJson('/api/core/reports/run', [
            'resource' => 'finance/project-costs', 'mode' => 'group',
            'row' => ['column' => 'cost_category'], 'measure' => ['agg' => 'count'],
        ])
            ->assertOk()
            ->assertJsonPath('data.descriptors.measure.type', 'number')
            ->assertJsonPath('data.descriptors.measure.label', 'Jumlah baris');
    }

    public function test_the_endpoints_require_a_session(): void
    {
        $this->getJson('/api/core/reports/resources')->assertStatus(401);
        $this->postJson('/api/core/reports/run', $this->definition())->assertStatus(401);
    }
}
