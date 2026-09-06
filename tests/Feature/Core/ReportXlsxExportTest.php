<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\SavedReport;
use Modules\Core\Support\SpaEnums;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Berkas XLSX laporan bebas (Fase 1 / P1-F).
 *
 * Yang diuji adalah SEL-nya, dibaca kembali dari berkas yang benar-benar
 * ditulis — bukan array yang menjadi sumbernya. Aturan "sel kosong, bukan 0"
 * adalah syarat paket ini, dan ia hanya berarti pada bentuk akhirnya: sebuah
 * `null` yang menjadi 0 di tengah jalan tidak terlihat di mana pun kecuali di
 * sel Excel yang dibaca orang.
 */
class ReportXlsxExportTest extends ErpTestCase
{
    private function financeUser(): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('finance', 'web');
        $role->syncPermissions(['fin.view', 'ast.view']);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Petugas Keuangan', 'email' => 'fin@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function seedAssets(): void
    {
        DB::table('ast_categories')->insert([
            'id' => 1, 'code' => 'KAT-UJI', 'name' => 'Kategori uji',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = [
            ['code' => 'AST-0001', 'ownership' => 'owned', 'status' => 'active', 'book_value' => 0],
            ['code' => 'AST-0002', 'ownership' => 'rented', 'status' => 'active', 'book_value' => null],
            ['code' => 'AST-0003', 'ownership' => 'owned', 'status' => 'maintenance', 'book_value' => 1500000],
        ];

        foreach ($rows as $row) {
            DB::table('ast_assets')->insert($row + [
                'name' => 'Aset '.$row['code'], 'category_id' => 1, 'useful_life_months' => 60,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function savedPivot(User $user): SavedReport
    {
        return SavedReport::query()->create([
            'user_id' => $user->getKey(),
            'name' => 'Nilai buku per kepemilikan',
            'resource' => 'assets/assets',
            'definition' => [
                'resource' => 'assets/assets', 'mode' => 'pivot',
                'row' => ['column' => 'ownership'], 'column' => ['column' => 'status'],
                'measure' => ['agg' => 'sum', 'column' => 'book_value'],
            ],
            'shared_roles' => null,
        ]);
    }

    /**
     * @return array<int, array<int, mixed>> baris → kolom → nilai sel, apa adanya
     */
    private function cellsOf(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'uji_xlsx_').'.xlsx';
        file_put_contents($path, $content);
        $sheet = IOFactory::load($path)->getActiveSheet();
        $out = $sheet->toArray(null, false, false, false);
        @unlink($path);

        return $out;
    }

    /**
     * Sel kosong, agregat NULL dan nol sungguhan tetap tiga hal berbeda DI
     * DALAM BERKASNYA.
     */
    public function test_an_empty_cell_stays_empty_and_a_real_zero_stays_zero_in_the_workbook(): void
    {
        $user = $this->financeUser();
        $this->seedAssets();
        $report = $this->savedPivot($user);

        $this->actingAs($user, 'sanctum');
        $response = $this->get("/api/core/reports/saved/{$report->id}/xlsx");
        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );

        $rows = $this->cellsOf($response->getContent());

        // Cari baris 'Sewa' dan 'Milik sendiri' — labelnya dari enums.js, bukan
        // nilai mentah 'rented'/'owned'.
        $byLabel = [];
        foreach ($rows as $line) {
            if (isset($line[0]) && in_array($line[0], ['Sewa', 'Milik sendiri'], true)) {
                $byLabel[$line[0]] = $line;
            }
        }

        $this->assertArrayHasKey('Sewa', $byLabel, 'Label enum harus dari enums.js, bukan nilai mentah.');
        $this->assertArrayHasKey('Milik sendiri', $byLabel);

        $owned = $byLabel['Milik sendiri'];
        $rented = $byLabel['Sewa'];

        // Nol SUNGGUHAN (aset milik sendiri bernilai buku 0) ditulis 0 — bukan kosong.
        $this->assertContains('0', array_map(static fn ($one): string => (string) $one, $owned),
            'Nol yang benar-benar dijumlahkan harus tertulis 0 di selnya.');

        // Baris 'Sewa': seluruh selnya kosong (nilai buku NULL + pasangan tanpa
        // baris), jadi tidak boleh ada satu pun '0' di baris itu.
        $rentedCells = array_slice($rented, 1);
        foreach ($rentedCells as $cell) {
            $this->assertNotSame('0', (string) $cell,
                'Alat sewa tidak ada di neraca kita: nilai buku NULL harus jadi sel KOSONG, bukan 0.');
        }
    }

    /** Enum dan FK dilabeli, bukan ditulis mentah. */
    public function test_enum_labels_come_from_the_same_file_the_screen_reads(): void
    {
        $this->assertSame('Sewa', SpaEnums::label('assetOwnership', 'rented'));
        $this->assertSame('Milik sendiri', SpaEnums::label('assetOwnership', 'owned'));

        // Nilai yang tidak dikenal ditulis apa adanya — lebih ringkas, tidak
        // pernah salah.
        $this->assertSame('sesuatu', SpaEnums::label('assetOwnership', 'sesuatu'));
        $this->assertSame('x', SpaEnums::label('enumYangTidakAda', 'x'));
    }

    /** Berkasnya menyebut nama laporannya — di judul dan di nama berkas. */
    public function test_the_workbook_names_the_report_it_came_from(): void
    {
        $user = $this->financeUser();
        $this->seedAssets();
        $report = $this->savedPivot($user);

        $this->actingAs($user, 'sanctum');
        $response = $this->get("/api/core/reports/saved/{$report->id}/xlsx")->assertOk();

        $this->assertStringContainsString('nilai-buku-per-kepemilikan', $response->headers->get('Content-Disposition'));

        $rows = $this->cellsOf($response->getContent());
        $this->assertSame('Nilai buku per kepemilikan', $rows[0][0]);
        $this->assertSame('Sumber', $rows[1][0]);
        $this->assertSame('Aset', $rows[1][1]);
    }

    /** Laporan yang tidak boleh dibaca pemanggil tidak bisa diekspor. */
    public function test_a_report_the_caller_may_not_read_cannot_be_exported(): void
    {
        $owner = $this->financeUser();
        $this->seedAssets();
        $report = $this->savedPivot($owner);

        $role = Role::findOrCreate('warehouse', 'web');
        $role->syncPermissions(['inv.view']);
        /** @var User $other */
        $other = User::query()->create([
            'name' => 'Gudang', 'email' => 'wh@test.local', 'password' => 'password', 'is_active' => true,
        ]);
        $other->assignRole($role);

        $this->actingAs($other, 'sanctum');
        $this->get("/api/core/reports/saved/{$report->id}/xlsx")->assertStatus(404);
    }

    /**
     * Aturan sel punya SATU pemilik.
     *
     * `XlsxSheetWriter::putRow` membawa badan `FormXlsxExportService::line()`,
     * dan perbandingannya harus tetap KETAT: `empty()` atau `!= ''` menulis sel
     * kosong untuk setiap nol yang sah — kebalikan persis dari aturan ini.
     */
    public function test_the_cell_rule_has_one_owner_and_stays_strict(): void
    {
        // KODE saja: docblock penulis MENYEBUT `empty($value)` dan `!= ''`
        // untuk menjelaskan kenapa keduanya salah, dan uji yang tidak bisa
        // membedakan prosa dari kode akan merah pada komentar yang
        // menjelaskannya.
        $source = '';
        foreach (token_get_all((string) file_get_contents(base_path('Modules/Core/Support/XlsxSheetWriter.php'))) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $source .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringContainsString("\$value !== null && \$value !== ''", $source);
        $this->assertStringNotContainsString('empty($value)', $source);
        $this->assertStringNotContainsString("!= ''", $source);
    }
}
