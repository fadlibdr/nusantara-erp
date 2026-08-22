<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Services\MasterDataImportService;
use Modules\Crm\Models\Customer;
use Modules\HrPayroll\Models\Employee;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Inventory\Models\Item;
use Modules\Procurement\Models\Vendor;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Bulk load and bulk export of master data.
 *
 * maatwebsite/excel was in composer.json from the first commit and called
 * nowhere. ProductionSeeder lays down a chart of accounts and two category trees
 * and stops — items 0, employees 0, vendors 0, customers 0 — so loading 2.000
 * items meant filling in 2.000 forms, and every other document in the system
 * points at one of those four tables.
 */
class MasterDataImportTest extends ErpTestCase
{
    private MasterDataImportService $imports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->imports = app(MasterDataImportService::class);
    }

    private function csv(string $body): string
    {
        return base64_encode($body);
    }

    private function vendorFile(string ...$rows): string
    {
        return $this->csv("kode,nama,npwp,pkp,kota,termin_bayar_hari\n".implode("\n", $rows)."\n");
    }

    // ------------------------------------------------------------- the happy path

    /**
     * The '#' comment marker is read from the identity column, not from the
     * physically first cell — because columns are matched by NAME, so the file
     * is free to put them in any order.
     *
     * A vendor list exported from someone else's system with `nama` first, and
     * a vendor whose registered name begins with a hash, used to lose that row
     * in silence while every row around it imported. Nothing counted it: not
     * `created`, not `skipped`, not `errors`.
     */
    public function test_a_hash_in_the_name_column_never_skips_a_vendor(): void
    {
        $result = $this->imports->commit('vendors', 'vendor.csv', $this->csv(
            "nama,kode,kota\n"
            ."#1 Rekanan Utama,VND-200,Jakarta\n"
            ."CV Baja Mandiri,VND-201,Bekasi\n"
        ));

        $this->assertSame(2, $result['created']);
        $this->assertSame('#1 Rekanan Utama', Vendor::query()->where('code', 'VND-200')->value('name'));
    }

    /** The template's own hint line is still a comment, wherever the split puts its text. */
    public function test_the_templates_own_hint_line_is_still_skipped(): void
    {
        $template = app(MasterDataImportService::class)->template('vendors');

        $result = $this->imports->commit('vendors', 'vendor.csv', $this->csv(
            $template."VND-202,PT Sesudah Petunjuk\n"
        ));

        $this->assertSame(1, $result['created']);
        $this->assertSame([], $result['errors'] ?? []);
    }

    public function test_a_file_of_vendors_becomes_vendors(): void
    {
        $result = $this->imports->commit('vendors', 'vendor.csv', $this->vendorFile(
            'VND-100,PT Semen Distribusi Utama,01.111.222.3-011.000,ya,Jakarta,30',
            'VND-101,CV Baja Mandiri,,tidak,Bekasi,45',
        ));

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['skipped']);

        $vendor = Vendor::query()->where('code', 'VND-100')->sole();
        $this->assertSame('PT Semen Distribusi Utama', $vendor->name);
        $this->assertTrue((bool) $vendor->is_pkp);
        $this->assertSame(45, (int) Vendor::query()->where('code', 'VND-101')->value('payment_term_days'));
    }

    /**
     * Re-running a corrected file is what people actually do: import, read the
     * errors, fix the sheet, import again. Matching on the business code is what
     * makes that safe instead of doubling the table.
     */
    public function test_an_existing_code_is_updated_rather_than_duplicated(): void
    {
        $this->imports->commit('vendors', 'v.csv', $this->vendorFile('VND-100,Nama Lama,,tidak,Jakarta,30'));
        $result = $this->imports->commit('vendors', 'v.csv', $this->vendorFile('VND-100,Nama Baru,,ya,Bandung,60'));

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, Vendor::query()->where('code', 'VND-100')->count());
        $this->assertSame('Nama Baru', Vendor::query()->where('code', 'VND-100')->value('name'));
    }

    /** Columns are matched by heading, because nobody's export is in our order. */
    public function test_columns_may_arrive_in_any_order(): void
    {
        $this->imports->commit('vendors', 'v.csv', $this->csv(
            "nama,termin_bayar_hari,kode\nPT Terbalik,14,VND-200\n",
        ));

        $vendor = Vendor::query()->where('code', 'VND-200')->sole();
        $this->assertSame('PT Terbalik', $vendor->name);
        $this->assertSame(14, (int) $vendor->payment_term_days);
    }

    // --------------------------------------------------------------- refusals

    /**
     * A required column missing from the FILE is one message, not 2.000 identical
     * row errors — and it is worth failing the whole upload for.
     */
    public function test_a_file_missing_a_required_column_is_refused_before_any_row_is_read(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Kolom wajib tidak ditemukan.*nama/');

        $this->imports->preview('vendors', 'v.csv', $this->csv("kode,kota\nVND-1,Jakarta\n"));
    }

    /** One bad row must not abandon the good ones, nor be half-written. */
    public function test_a_bad_row_is_skipped_and_reported_while_the_rest_land(): void
    {
        $result = $this->imports->commit('vendors', 'v.csv', $this->vendorFile(
            'VND-300,PT Baik,,ya,Jakarta,30',
            ',PT Tanpa Kode,,ya,Jakarta,30',
            'VND-302,PT Juga Baik,,tidak,Surabaya,30',
        ));

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(3, $result['rows'][0]['line'], 'the reported line number is the one in the sheet');
        $this->assertStringContainsString('kode', $result['rows'][0]['errors'][0]);
        $this->assertSame(2, Vendor::query()->count());
    }

    /**
     * The same code twice in one file would silently overwrite itself and be
     * reported as two successes for one record.
     */
    public function test_a_code_repeated_within_one_file_is_refused(): void
    {
        $result = $this->imports->commit('vendors', 'v.csv', $this->vendorFile(
            'VND-400,PT Pertama,,ya,Jakarta,30',
            'VND-400,PT Kedua,,ya,Jakarta,30',
        ));

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('muncul dua kali', $result['rows'][0]['errors'][0]);
    }

    public function test_a_value_outside_its_allowed_set_is_refused(): void
    {
        $preview = $this->imports->preview('vendors', 'v.csv', $this->csv(
            "kode,nama,status\nVND-500,PT Salah Status,dibekukan\n",
        ));

        $this->assertFalse($preview['rows'][0]['valid']);
        $this->assertStringContainsString('status', $preview['rows'][0]['errors'][0]);
    }

    public function test_an_unreadable_file_type_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Format berkas tidak didukung/');

        $this->imports->preview('vendors', 'daftar.pdf', $this->csv("kode,nama\nA,B\n"));
    }

    /**
     * A .xlsx is what an officer actually has. maatwebsite/excel exists in this
     * project for exactly this row and had never been called.
     */
    public function test_a_real_xlsx_workbook_is_read(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['kode', 'nama', 'kota', 'termin_bayar_hari'],
            ['VND-XLS', 'PT Dari Excel', 'Semarang', 21],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'test_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $content = base64_encode((string) file_get_contents($path));
        @unlink($path);

        $result = $this->imports->commit('vendors', 'vendor.xlsx', $content);

        $this->assertSame(1, $result['created']);
        $this->assertSame(21, (int) Vendor::query()->where('code', 'VND-XLS')->value('payment_term_days'));
    }

    /** A European Excel writes semicolons, and the file is still a CSV. */
    public function test_a_semicolon_separated_file_is_read(): void
    {
        $result = $this->imports->commit('vendors', 'v.csv', $this->csv(
            "kode;nama;kota\nVND-SEMI;PT Titik Koma;Medan\n",
        ));

        $this->assertSame(1, $result['created']);
        $this->assertSame('PT Titik Koma', Vendor::query()->where('code', 'VND-SEMI')->value('name'));
    }

    /**
     * Excel prefixes a UTF-8 BOM. Left in place it becomes part of the first
     * column's name and "kode" stops matching — for a file that looks perfect.
     */
    public function test_a_byte_order_mark_does_not_hide_the_first_column(): void
    {
        $result = $this->imports->commit('vendors', 'v.csv', $this->csv(
            "\xEF\xBB\xBFkode,nama\nVND-BOM,PT Berkas Excel\n",
        ));

        $this->assertSame(1, $result['created']);
    }

    /**
     * A blank first line is not a column list.
     *
     * The reader keeps blank lines now — it has to, so the document importer can
     * report the file's real line numbers — so this importer, whose header IS
     * its first row, skips past them itself rather than reading an empty row as
     * its headings.
     */
    public function test_a_blank_first_line_does_not_become_the_header_row(): void
    {
        $result = $this->imports->commit('vendors', 'v.csv', $this->csv(
            "\nkode,nama\nVND-KOSONG,PT Baris Kosong\n",
        ));

        $this->assertSame(1, $result['created']);
        $this->assertSame('PT Baris Kosong', Vendor::query()->where('code', 'VND-KOSONG')->value('name'));
    }

    // ------------------------------------------------------------------ casts

    /** An Indonesian sheet writes 1.250.000,50, and it means one and a quarter million. */
    public function test_indonesian_number_formatting_survives(): void
    {
        $this->seedCategory();

        $this->imports->commit('items', 'i.csv', $this->csv(
            "kode,nama,satuan,kategori_kode,harga_beli_terakhir\nITM-900,Semen Portland 50kg,zak,SIPIL,\"1.250.000,50\"\n",
        ));

        $this->assertEqualsWithDelta(
            1_250_000.50,
            (float) Item::query()->where('code', 'ITM-900')->value('last_price'),
            0.01,
        );
    }

    /**
     * 03/04/2026 is 3 April in an Indonesian sheet and 4 March to strtotime.
     * Getting it wrong shifts somebody's PTKP year and their whole PPh 21.
     */
    public function test_a_day_first_date_is_not_read_as_month_first(): void
    {
        $this->importEmployee('03/04/2026');

        $this->assertSame('2026-04-03', Employee::query()->where('code', 'EMP-900')->value('join_date')->toDateString());
    }

    public function test_an_iso_date_is_read_as_written(): void
    {
        $this->importEmployee('2026-04-03');

        $this->assertSame('2026-04-03', Employee::query()->where('code', 'EMP-900')->value('join_date')->toDateString());
    }

    public function test_a_date_that_is_not_a_date_is_reported_against_its_own_column(): void
    {
        $preview = $this->imports->preview('employees', 'e.csv', $this->employeeFile('kemarin'));

        $this->assertFalse($preview['rows'][0]['valid']);
        $this->assertStringContainsString('tanggal_masuk', implode(' ', $preview['rows'][0]['errors']));
    }

    private function employeeFile(string $joinDate): string
    {
        return $this->csv(
            "kode,nama,nik_ktp,jenis_kelamin,tanggal_lahir,status_ptkp,tanggal_masuk,jenis_hubungan_kerja,jabatan,departemen\n"
            ."EMP-900,Budi Santoso,3201010101900001,male,1990-01-01,K/1,{$joinDate},tetap,Pelaksana,proyek\n",
        );
    }

    private function importEmployee(string $joinDate): void
    {
        $this->imports->commit('employees', 'e.csv', $this->employeeFile($joinDate));
    }

    // ------------------------------------------------------------- the lookup

    public function test_a_category_is_matched_by_its_code(): void
    {
        $categoryId = DB::table('inv_item_categories')->insertGetId([
            'code' => 'SIPIL', 'name' => 'Sipil', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->imports->commit('items', 'i.csv', $this->csv(
            "kode,nama,satuan,kategori_kode\nITM-901,Besi Beton D16,btg,SIPIL\n",
        ));

        $this->assertSame($categoryId, (int) Item::query()->where('code', 'ITM-901')->value('category_id'));
    }

    /** A category code that does not exist is named, not silently nulled. */
    public function test_an_unknown_category_code_is_reported(): void
    {
        $preview = $this->imports->preview('items', 'i.csv', $this->csv(
            "kode,nama,satuan,kategori_kode\nITM-902,Entah Apa,bh,TIDAK-ADA\n",
        ));

        $this->assertFalse($preview['rows'][0]['valid']);
        $this->assertStringContainsString('TIDAK-ADA', $preview['rows'][0]['errors'][0]);
    }

    private function seedCategory(string $code = 'SIPIL'): int
    {
        return DB::table('inv_item_categories')->insertGetId([
            'code' => $code, 'name' => 'Sipil', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- preview

    /** Nothing is written until somebody has seen what would happen. */
    public function test_a_preview_writes_nothing(): void
    {
        $preview = $this->imports->preview('vendors', 'v.csv', $this->vendorFile(
            'VND-600,PT Belum Masuk,,ya,Jakarta,30',
        ));

        $this->assertSame(1, $preview['summary']['to_create']);
        $this->assertSame(0, Vendor::query()->count());
    }

    public function test_the_preview_says_which_rows_would_be_updated(): void
    {
        Vendor::query()->create([
            'code' => 'VND-700', 'name' => 'Sudah Ada', 'classification' => 'material',
            'payment_term_days' => 30, 'status' => 'active',
        ]);

        $preview = $this->imports->preview('vendors', 'v.csv', $this->vendorFile(
            'VND-700,PT Sudah Ada,,ya,Jakarta,30',
            'VND-701,PT Baru,,ya,Jakarta,30',
        ));

        $this->assertSame(1, $preview['summary']['to_update']);
        $this->assertSame(1, $preview['summary']['to_create']);
        $this->assertSame('update', $preview['rows'][0]['action']);
    }

    // --------------------------------------------------------------- template

    public function test_the_template_carries_every_column_and_names_the_required_ones(): void
    {
        $template = $this->imports->template('employees');

        $this->assertStringContainsString('kode,nama,nik_ktp', $template);
        $this->assertStringContainsString('# wajib diisi:', $template);
        $this->assertStringContainsString('status_ptkp', $template);
    }

    /** The template's own hint line must survive a round trip. */
    public function test_the_template_can_be_filled_in_and_sent_straight_back(): void
    {
        $template = $this->imports->template('vendors');
        $filled = $template.'VND-800,PT Dari Template'.str_repeat(',', 15)."\n";

        $result = $this->imports->commit('vendors', 'v.csv', $this->csv($filled));

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['skipped'], 'the "# wajib diisi" line must not be read as a record');
    }

    // ----------------------------------------------------------------- export

    public function test_the_export_round_trips_back_through_the_importer(): void
    {
        Customer::query()->create([
            'code' => 'CUST-900', 'name' => 'PT Graha Sentosa', 'is_pkp' => true,
            'city' => 'Jakarta', 'payment_term_days' => 30, 'status' => 'active',
        ]);

        $exported = $this->imports->export('customers');
        Customer::query()->where('code', 'CUST-900')->forceDelete();

        $result = $this->imports->commit('customers', 'c.csv', $this->csv($exported));

        $this->assertSame(1, $result['created']);
        $this->assertTrue((bool) Customer::query()->where('code', 'CUST-900')->value('is_pkp'));
    }

    /**
     * The round trip has to survive the formula guard as well.
     *
     * The export prefixes an apostrophe to a cell Excel would run as a formula
     * — correct, and it opens on somebody's machine — but nothing stripped it
     * on the way back in, so one round trip through the endpoint that exists
     * FOR round trips renamed the customer to "'- PT Graha Sentosa" for good.
     */
    public function test_the_export_round_trip_does_not_keep_the_formula_guard(): void
    {
        Customer::query()->create([
            'code' => 'CUST-901', 'name' => '- PT Graha Sentosa', 'is_pkp' => true,
            'city' => 'Jakarta', 'payment_term_days' => 30, 'status' => 'active',
        ]);

        $exported = $this->imports->export('customers');
        $this->assertStringContainsString("'- PT Graha Sentosa", $exported);

        Customer::query()->where('code', 'CUST-901')->forceDelete();
        $this->imports->commit('customers', 'c.csv', $this->csv($exported));

        $this->assertSame('- PT Graha Sentosa', Customer::query()->where('code', 'CUST-901')->value('name'));
    }

    /**
     * Excel executes a cell that starts with "=". An exported vendor name is
     * attacker-supplied text, and it opens on somebody's machine.
     */
    public function test_the_export_neutralises_a_cell_that_would_run_as_a_formula(): void
    {
        Vendor::query()->create([
            'code' => 'VND-950', 'name' => '=HYPERLINK("http://jahat.example","klik")',
            'classification' => 'material', 'payment_term_days' => 30, 'status' => 'active',
        ]);

        $this->assertStringContainsString("'=HYPERLINK", $this->imports->export('vendors'));
    }

    // -------------------------------------------------------------- endpoints

    public function test_the_endpoint_previews_then_imports(): void
    {
        $admin = $this->adminUser();
        $payload = ['filename' => 'vendor.csv', 'content' => $this->vendorFile('VND-990,PT Lewat API,,ya,Jakarta,30')];

        $this->actingAs($admin)->postJson('/api/core/master-data/vendors/preview', $payload)
            ->assertOk()
            ->assertJsonPath('data.summary.to_create', 1);

        $this->assertSame(0, Vendor::query()->count(), 'a preview writes nothing');

        $this->actingAs($admin)->postJson('/api/core/master-data/vendors/import', $payload)
            ->assertOk()
            ->assertJsonPath('data.created', 1);
    }

    public function test_the_template_and_export_endpoints_serve_csv(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->get('/api/core/master-data/vendors/template')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->actingAs($admin)->get('/api/core/master-data/customers/export')->assertOk();
    }

    public function test_an_unknown_resource_is_a_not_found(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/api/core/master-data/kucing/template')
            ->assertNotFound();
    }

    /**
     * An import both creates and updates, so it needs both rights. Somebody who
     * may only create must not be able to rewrite the table by uploading a sheet.
     */
    public function test_importing_needs_update_as_well_as_create(): void
    {
        $user = $this->userWithOnly(['prc.view', 'prc.create']);

        $this->actingAs($user)
            ->postJson('/api/core/master-data/vendors/import', [
                'filename' => 'v.csv',
                'content' => $this->vendorFile('VND-995,PT Menyelinap,,ya,Jakarta,30'),
            ])
            ->assertForbidden();

        $this->assertSame(0, Vendor::query()->count());
    }

    /** The list only offers what the caller may actually read. */
    public function test_the_list_hides_tables_the_caller_cannot_see(): void
    {
        $response = $this->actingAs($this->userWithOnly(['prc.view']))
            ->getJson('/api/core/master-data')
            ->assertOk();

        $keys = array_column($response->json('data'), 'key');
        $this->assertSame(['vendors'], $keys);
        $this->assertFalse($response->json('data.0.can_import'), 'view alone is not enough to import');
    }

    private function userWithOnly(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('terbatas', 'web');
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Petugas', 'email' => 'petugas@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
