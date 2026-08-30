<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Modules\Core\Services\FormPrintService;
use Modules\Core\Services\FormXlsxExportService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Inventory\Models\StockBalance;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\DailyReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * P8 — ekspor XLSX untuk formulir rumah tersering. Kontraknya satu kalimat:
 * yang diekspor adalah data yang SAMA dengan yang disusun komposer cetak
 * (FormPrintService::composed), tidak ada angka yang dihitung ulang, dan sel
 * yang di kertas bergaris (tidak bersumber) adalah sel KOSONG di XLSX — tidak
 * pernah 0.
 */
class FormXlsxExportTest extends ErpTestCase
{
    use InventoryFixtures;

    private function userWith(string $permission): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('role-'.str_replace('.', '-', $permission), 'web');
        $role->givePermissionTo($permission);

        $user = User::query()->create([
            'name' => 'Penguji Ekspor',
            'email' => str()->random(10).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<int, array<int, mixed>> the sheet as rows of cells. */
    private function sheetRows(string $binary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx_export_').'.xlsx';
        file_put_contents($path, $binary);

        try {
            $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false);
        } finally {
            @unlink($path);
        }

        return $rows;
    }

    /** The first row whose first non-empty cell equals $needle. */
    private function rowWhereFirstCell(array $rows, string $needle): ?array
    {
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if ($cell === null || $cell === '') {
                    continue;
                }

                if ($cell === $needle) {
                    return $row;
                }

                break; // sel pertama baris ini bukan needle; baris berikutnya.
            }
        }

        return null;
    }

    private function rowContaining(array $rows, string $needle): ?array
    {
        foreach ($rows as $row) {
            if (in_array($needle, $row, true)) {
                return $row;
            }
        }

        return null;
    }

    public function test_the_ten_slug_whitelist_resolves_against_the_print_registry(): void
    {
        $slugs = FormXlsxExportService::FORMS;

        $this->assertCount(10, $slugs);

        $forms = app(FormPrintService::class);

        foreach ($slugs as $slug) {
            // definition() melempar untuk slug tak dikenal — pin bahwa daftar
            // ekspor tidak bisa menyimpang diam-diam dari registri cetak.
            $this->assertIsArray($forms->definition($slug));
        }

        // Katalog cetak MENGUMUMKAN daftar ini (kunci `xlsx` per baris) supaya
        // tombol ekspor SPA tidak menyalinnya: satu pemilik, satu daftar.
        $catalogue = $this->actingAs($this->adminUser())
            ->getJson('/api/core/print/forms')->assertOk()->json('data');

        $flagged = array_column(array_filter($catalogue, fn (array $row): bool => $row['xlsx'] ?? false), 'slug');
        sort($flagged);
        $expected = $slugs;
        sort($expected);
        $this->assertSame($expected, $flagged);
    }

    public function test_laporan_harian_exports_the_composer_values_with_empty_cells_where_the_paper_rules(): void
    {
        $project = Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-02-01',
            'end_date' => '2026-12-31',
        ]);

        $report = app(DailyReportService::class)->create([
            'project_id' => $project->id,
            'report_date' => '2026-03-01',
            'activities' => 'Pengecoran kolom lantai 2 zona A',
            'manpower' => [
                ['role_key' => 'mandor_sipil', 'headcount' => 12],
            ],
        ]);

        $response = $this->actingAs($this->userWith('prj.view'))
            ->get("/api/core/print/forms/laporan-harian/{$report->id}/xlsx");

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));

        $rows = $this->sheetRows((string) $response->getContent());

        $this->assertNotNull($this->rowContaining($rows, 'LAPORAN HARIAN'));

        // Nilai komposer, bukan hitung ulang: baris jabatan pad "Mandor Sipil
        // + Tukang" (label enumnya, huruf demi huruf) membawa 12.
        $mandor = $this->rowContaining($rows, 'Mandor Sipil + Tukang');
        $this->assertNotNull($mandor);
        $this->assertSame(12.0, (float) $mandor[array_search('Mandor Sipil + Tukang', $mandor, true) + 1]);

        // Jabatan tanpa entri hari itu: di kertas bergaris, di XLSX KOSONG —
        // bukan 0. (Danlat ada di pad FM-10-12 tetapi tidak diisi.)
        $danlat = $this->rowContaining($rows, 'Danlat');
        $this->assertNotNull($danlat);
        $cell = $danlat[array_search('Danlat', $danlat, true) + 1] ?? null;
        $this->assertTrue($cell === null || $cell === '', 'Sel jabatan kosong harus kosong, bukan '.var_export($cell, true));
    }

    public function test_saldo_stok_exports_the_registry_composed_tables(): void
    {
        $warehouse = $this->makeWarehouse('WH-01'); // tanpa proyek: PROYEK bergaris
        $item = $this->makeItem('Semen 40kg', ['code' => 'ITM-01']);
        StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'item_id' => $item->id,
            'qty' => 100,
            'avg_cost' => 15000,
        ]);

        $response = $this->actingAs($this->userWith('inv.view'))
            ->get("/api/core/print/forms/saldo-stok/{$warehouse->id}/xlsx");

        $response->assertOk();
        $rows = $this->sheetRows((string) $response->getContent());

        // Baris identitas PROYEK: gudang pusat tidak melayani proyek — kosong,
        // bukan 0 dan bukan teks karangan.
        $proyek = $this->rowWhereFirstCell($rows, 'PROYEK');
        $this->assertNotNull($proyek);
        $this->assertTrue(($proyek[1] ?? null) === null || ($proyek[1] ?? '') === '');

        // Baris saldo membawa string komposer apa adanya: qty '100', HPP
        // '15.000,00', nilai '1.500.000,00' — format cetaknya, bukan hitung
        // ulang milik exporter.
        $saldo = $this->rowContaining($rows, 'ITM-01');
        $this->assertNotNull($saldo);
        $this->assertContains('100', $saldo);
        $this->assertContains('15.000,00', $saldo);
        $this->assertContains('1.500.000,00', $saldo);
    }

    public function test_a_form_outside_the_whitelist_is_refused_with_its_name(): void
    {
        $project = Project::query()->create([
            'code' => 'PRJ-X',
            'name' => 'Proyek X',
            'type' => 'construction',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->userWith('prj.view'))
            ->get("/api/core/print/forms/data-proyek/{$project->id}/xlsx");

        $response->assertStatus(422);
        $this->assertStringContainsString('data-proyek', (string) $response->json('message'));
        $this->assertStringContainsString('XLSX', (string) $response->json('message'));
    }
}
