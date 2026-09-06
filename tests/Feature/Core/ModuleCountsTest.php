<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\ModuleCounts;
use Modules\Core\Support\SpaNav;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Inventory\Services\StockService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Registri ModuleCounts (P1-C, T1C.2) — SATU angka utama per modul.
 *
 * Ubin launcher `#/home` dan kepala beranda modul `#/m/<prefix>` memimpin
 * dengan angka ini, jadi tiga sifatnya diuji di sini dan bukan dipercaya:
 *
 *  - LENGKAP. Setiap grup NAV punya entri dan sebaliknya. Grup tanpa entri =
 *    ubin tanpa angka selamanya; entri tanpa grup = kueri yang berjalan setiap
 *    boot untuk angka yang tidak pernah dilihat siapa pun.
 *  - BERGERBANG. Tanpa izinnya entri TIDAK ADA — bukan 0. "0 tiket" pada layar
 *    orang yang memang tidak boleh melihat tiket adalah kebohongan yang tampak
 *    seperti kabar baik.
 *  - JUJUR SAAT RUSAK. Tabel belum ada (tim lain sedang bermigrasi) → entri
 *    tidak ada; kueri melempar → count null + peringatan di log, TIDAK PERNAH
 *    500 yang menjatuhkan seluruh dasbor karena satu modul.
 */
class ModuleCountsTest extends ErpTestCase
{
    /** Nomor urut supaya kolom `code` yang unik tidak bertabrakan antar fixture. */
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        ModuleCounts::flushSchemaMemo();
    }

    // ------------------------------------------------------------- kelengkapan

    public function test_every_nav_group_has_an_entry_and_every_entry_a_nav_group(): void
    {
        $prefixes = SpaNav::prefixes();

        $this->assertGreaterThan(10, count($prefixes),
            'Only '.count($prefixes).' NAV prefixes were read from schema.js; SpaNav no longer reads NAV and this test '
            .'would pass for any registry.');

        // Urutan ikut diuji: launcher menggambar ubin dalam urutan NAV, dan
        // urutan registri inilah yang dipakainya bila NAV tidak tersedia.
        $this->assertSame($prefixes, array_keys(ModuleCounts::entries()));
    }

    public function test_every_entry_declares_a_label_a_unit_and_the_tables_it_reads(): void
    {
        foreach (ModuleCounts::entries() as $prefix => $entry) {
            $this->assertNotEmpty($entry['label'], "Entri {$prefix} tanpa label KPI.");
            $this->assertNotEmpty($entry['unit'], "Entri {$prefix} tanpa satuan — ubin akan menulis angka telanjang.");
            $this->assertNotEmpty($entry['tables'], "Entri {$prefix} tidak menyebut tabel yang dibacanya, jadi Schema::hasTable tidak menjaga apa pun.");
            $this->assertIsCallable($entry['count']);
        }
    }

    // ---------------------------------------------------------------- gerbang

    public function test_an_entry_without_its_permission_is_absent_not_zero(): void
    {
        // Pengguna tanpa satu pun izin: hanya entri tak-berizin (Ringkasan) yang tersisa.
        $bare = $this->userWith([]);

        $counts = collect(ModuleCounts::for($bare))->keyBy('prefix');

        $withPermission = array_keys(array_filter(ModuleCounts::entries(), fn (array $entry) => $entry['permission'] !== null));
        foreach ($withPermission as $prefix) {
            $this->assertFalse($counts->has($prefix), "Modul {$prefix} muncul untuk pengguna tanpa izinnya.");
        }

        $this->assertTrue($counts->has('ringkasan'), 'Ringkasan tidak berizin dan harus selalu ada.');
    }

    public function test_holding_only_one_permission_yields_only_that_entry(): void
    {
        $user = $this->userWith(['svc.view']);

        $prefixes = array_column(ModuleCounts::for($user), 'prefix');

        sort($prefixes);
        $this->assertSame(['ringkasan', 'svc'], $prefixes);
    }

    // ---------------------------------------------------------------- angkanya

    public function test_each_entry_counts_exactly_its_own_fixture(): void
    {
        $admin = $this->adminUser();
        $this->seedFixtures($admin);

        $counts = collect(ModuleCounts::for($admin))->pluck('count', 'prefix')->all();

        $this->assertSame([
            'ringkasan' => 2,   // 2 notifikasi belum dibaca, 1 sudah dibaca
            'crm' => 3,         // new + contacted + qualified; won & lost tidak dihitung
            'est' => 1,         // 1 RAB submitted; draft & approved tidak
            'eng' => 1,         // 1 SDS tanpa keputusan & belum disuperseded
            'prj' => 2,         // active + finishing; completed tidak
            'qc' => 2,          // open + under_correction; verified & closed tidak
            'prc' => 1,         // 1 PO approved = terbuka; draft/submitted/closed tidak
            'inv' => 1,         // 1 baris gudang×item di bawah min; item nonaktif tidak
            'scm' => 1,         // 1 opname subkon submitted
            'fin' => 1,         // 1 invoice approved dengan sisa; lunas tidak
            'hr' => 1,          // 1 cuti submitted
            'svc' => 3,         // open + assigned + in_progress; resolved & closed tidak
            'ast' => 1,         // 1 aset berstatus maintenance
            'iam' => 2,         // 2 job gagal
        ], $counts);
    }

    /**
     * Dua angka yang SUDAH punya pemilik lain di aplikasi ini harus sama persis:
     * registri yang menghitung "proyek aktif" atau "invoice belum lunas"
     * berbeda dari dasbor akan membuat dua layar berdebat tentang satu kenyataan.
     */
    public function test_project_and_invoice_counts_agree_with_the_dashboard_summary(): void
    {
        $admin = $this->adminUser();
        $this->seedFixtures($admin);
        $this->actingAs($admin, 'sanctum');

        $summary = $this->getJson('/api/core/dashboard/summary')->assertOk();
        $counts = collect(ModuleCounts::for($admin))->pluck('count', 'prefix');

        $this->assertSame($summary->json('data.projects.active_count'), $counts['prj']);
        $this->assertSame($summary->json('data.ar_invoices.open_count'), $counts['fin']);
    }

    /**
     * …dan yang ketiga: "item di bawah stok minimum" sudah dihitung
     * StockService::lowStockAlerts() untuk layar Saldo Stok. Core tidak boleh
     * mengimpor Inventory, jadi kueri itu DISALIN sebagai DB::table di registri
     * — dan salinan yang menyimpang adalah persis yang diuji di sini.
     */
    public function test_the_low_stock_count_equals_the_stock_screens_own_query(): void
    {
        $admin = $this->adminUser();
        $this->seedFixtures($admin);

        $counts = collect(ModuleCounts::for($admin))->pluck('count', 'prefix');

        $this->assertSame(app(StockService::class)->lowStockAlerts()->count(), $counts['inv']);
    }

    // ------------------------------------------------------------- degradasi

    public function test_a_table_that_does_not_exist_yet_makes_the_entry_absent(): void
    {
        $this->skipUnlessTransactionalDdl();

        $admin = $this->adminUser();
        $this->assertContains('svc', array_column(ModuleCounts::for($admin), 'prefix'));

        Schema::drop('svc_ticket_activities');
        Schema::drop('svc_tickets');
        ModuleCounts::flushSchemaMemo();

        $prefixes = array_column(ModuleCounts::for($admin), 'prefix');
        $this->assertNotContains('svc', $prefixes, 'Modul tanpa tabelnya melaporkan angka, bukan diam.');
        // …dan modul lain tidak ikut hilang.
        $this->assertContains('prj', $prefixes);
    }

    public function test_a_query_that_throws_yields_null_and_a_logged_warning(): void
    {
        $this->skipUnlessTransactionalDdl();

        $admin = $this->adminUser();
        Log::spy();

        /*
         * Tabelnya ada, kolomnya tidak: persis bentuk kegagalan saat tim lain
         * mengganti nama kolom di lane-nya sendiri.
         *
         * Kolomnya `fin_ar_invoices.amount_paid` dan bukan sembarang kolom, dan
         * itu bukan kebetulan: SQLite memperlakukan identifier berkutip-ganda
         * yang TIDAK cocok dengan kolom mana pun sebagai LITERAL STRING (quirk
         * kompatibilitas yang masih menyala di 3.4x). Laravel mengutip setiap
         * kolom dengan kutip ganda, jadi `where "status" in ('active')` pada
         * tabel tanpa kolom status tidak melempar apa-apa — ia mengembalikan 0,
         * diam-diam, yang persis kebohongan yang dilarang paket ini. Satu-
         * satunya jalur di registri yang menyebut kolom TANPA kutip adalah
         * whereRaw('total - amount_paid > 0') milik entri fin. (Temuan ini juga
         * alasan uji "tabel tidak ada" di atas memakai Schema::drop dan bukan
         * penghapusan kolom.)
         */
        DB::statement('ALTER TABLE fin_ar_invoices DROP COLUMN amount_paid');
        ModuleCounts::flushSchemaMemo();

        $counts = collect(ModuleCounts::for($admin))->pluck('count', 'prefix');

        $this->assertTrue($counts->has('fin'), 'Entri hilang seluruhnya; yang benar adalah entri ada dengan angka null.');
        $this->assertNull($counts['fin'], 'Kueri yang melempar melaporkan angka, bukan "tidak tahu".');
        $this->assertNotNull($counts['prj'], 'Satu modul yang rusak menjatuhkan modul lain.');

        Log::shouldHaveReceived('warning')->once();
    }

    // -------------------------------------------------------------- endpoint

    public function test_dashboard_summary_carries_the_modules_block_only_when_asked(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin, 'sanctum');

        // Tanpa ?include=modules jumlah permintaan dan bentuk jawaban dasbor
        // tidak berubah sedikit pun (target metrik Fase 1: dasbor tidak boleh
        // bertambah berat karena paket ini).
        $this->getJson('/api/core/dashboard/summary')->assertOk()->assertJsonMissingPath('data.modules');

        $this->getJson('/api/core/dashboard/summary?include=modules')
            ->assertOk()
            ->assertJsonPath('data.projects.active_count', 0)
            ->assertJsonStructure(['data' => ['modules' => [['prefix', 'label', 'unit', 'count']]]]);
    }

    public function test_the_modules_endpoint_answers_the_same_payload_as_the_dashboard_block(): void
    {
        $admin = $this->adminUser();
        $this->seedFixtures($admin);
        $this->actingAs($admin, 'sanctum');

        $launcher = $this->getJson('/api/core/modules')->assertOk()->json('data');
        $block = $this->getJson('/api/core/dashboard/summary?include=modules')->assertOk()->json('data.modules');

        $this->assertSame($block, $launcher);
        $this->assertSame(array_keys(ModuleCounts::entries()), array_column($launcher, 'prefix'));
    }

    public function test_the_modules_endpoint_requires_a_session(): void
    {
        $this->getJson('/api/core/modules')->assertStatus(401);
    }

    public function test_the_modules_endpoint_hides_what_the_caller_may_not_read(): void
    {
        $this->actingAs($this->userWith(['inv.view']), 'sanctum');

        $prefixes = array_column($this->getJson('/api/core/modules')->assertOk()->json('data'), 'prefix');

        sort($prefixes);
        $this->assertSame(['inv', 'ringkasan'], $prefixes);
    }

    // -------------------------------------------------------------- fixtures

    private function skipUnlessTransactionalDdl(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // DDL di MySQL melakukan COMMIT IMPLISIT: transaksi tes berakhir dan
            // RefreshDatabase menjadwalkan migrate:fresh (~27 dtk) untuk tes
            // berikutnya — lihat tests/Support/FixtureSchema.
            $this->markTestSkipped('DDL di dalam tes hanya aman di SQLite.');
        }
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pemegang Izin',
            'email' => substr(md5(implode('|', $permissions)), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function code(string $prefix): string
    {
        return sprintf('%s-%04d', $prefix, ++$this->seq);
    }

    private function insert(string $table, array $row): int
    {
        return (int) DB::table($table)->insertGetId($row + ['created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Satu baris yang MASUK hitungan dan satu yang tidak, per modul — kalau
     * fixture-nya hanya berisi baris yang masuk, sebuah WHERE yang hilang tetap
     * lulus.
     */
    private function seedFixtures(User $admin): void
    {
        // ringkasan — 2 belum dibaca, 1 sudah, 1 milik orang lain.
        $other = $this->insert('users', ['name' => 'Orang Lain', 'email' => 'lain@test.local', 'password' => 'x', 'is_active' => true]);
        foreach ([null, null, now()] as $readAt) {
            $this->insert('core_notifications', ['user_id' => $admin->id, 'event' => 'document.submitted', 'title' => 'Uji', 'read_at' => $readAt]);
        }
        $this->insert('core_notifications', ['user_id' => $other, 'event' => 'document.submitted', 'title' => 'Uji', 'read_at' => null]);

        // crm — 3 terbuka, 2 selesai.
        foreach (['new', 'contacted', 'qualified', 'won', 'lost'] as $status) {
            $this->insert('crm_leads', ['code' => $this->code('LEAD'), 'name' => 'Prospek', 'status' => $status]);
        }

        // est — 1 submitted, 2 bukan.
        foreach (['submitted', 'draft', 'approved'] as $status) {
            $this->insert('est_boqs', ['code' => $this->code('BOQ'), 'title' => 'RAB', 'status' => $status]);
        }

        // prj — 2 aktif (active + finishing), 1 selesai.
        $projects = [];
        foreach (['active', 'finishing', 'completed'] as $status) {
            $projects[$status] = $this->insert('prj_projects', ['code' => $this->code('PRJ'), 'name' => 'Proyek', 'type' => 'construction', 'status' => $status]);
        }

        // eng — 1 menunggu keputusan, 1 sudah diputus, 1 sudah disuperseded.
        $drawing = $this->insert('eng_drawings', ['project_id' => $projects['active'], 'number' => $this->code('DWG'), 'title' => 'Denah', 'discipline' => 'structure']);
        // Revisi berbeda per baris: UNIQUE(drawing_id, revision) di register gambar.
        foreach ([[null, null], ['approved', null], [null, now()]] as $index => [$decision, $superseded]) {
            $this->insert('eng_drawing_submittals', [
                'code' => $this->code('SDS'), 'drawing_id' => $drawing, 'revision' => "R{$index}",
                'submitted_at' => now()->toDateString(), 'reviewer_party' => 'mk',
                'decision' => $decision, 'superseded_at' => $superseded,
            ]);
        }

        // qc — 2 terbuka (open + under_correction), 2 selesai.
        $location = $this->insert('core_locations', ['project_id' => $projects['active'], 'kind' => 'zone', 'code' => $this->code('LOC'), 'name' => 'Zona A']);
        foreach (['open', 'under_correction', 'verified', 'closed'] as $status) {
            $this->insert('qc_ncr', [
                'code' => $this->code('NCR'), 'project_id' => $projects['active'], 'location_id' => $location,
                'stage' => 'pelaksanaan', 'description' => 'Tidak sesuai', 'status' => $status,
            ]);
        }

        // prc — 1 terbuka (approved), 3 bukan; `closed` adalah PO yang barangnya sudah lengkap.
        $vendor = $this->insert('prc_vendors', ['code' => $this->code('VND'), 'name' => 'PT Uji', 'classification' => 'supplier']);
        foreach (['approved', 'draft', 'submitted', 'closed'] as $status) {
            $this->insert('prc_purchase_orders', ['code' => $this->code('PO'), 'vendor_id' => $vendor, 'order_date' => now()->toDateString(), 'status' => $status]);
        }

        // inv — 1 baris di bawah min, 1 di atas, 1 di bawah tapi itemnya nonaktif.
        $category = $this->insert('inv_item_categories', ['code' => $this->code('CAT'), 'name' => 'Semen']);
        $warehouse = $this->insert('inv_warehouses', ['code' => $this->code('WH'), 'name' => 'Gudang']);
        foreach ([[true, 10, 2], [true, 10, 40], [false, 10, 1]] as [$active, $min, $qty]) {
            $item = $this->insert('inv_items', ['code' => $this->code('ITM'), 'name' => 'Item', 'category_id' => $category, 'unit' => 'sak', 'min_stock' => $min, 'is_active' => $active]);
            $this->insert('inv_stock_balances', ['warehouse_id' => $warehouse, 'item_id' => $item, 'qty' => $qty]);
        }

        // scm — 1 opname submitted, 1 draft.
        $subcontract = $this->insert('scm_subcontracts', ['code' => $this->code('SPK'), 'vendor_id' => $vendor, 'title' => 'Pekerjaan', 'pph_scheme' => 'final_2_65']);
        foreach (['submitted', 'draft'] as $index => $status) {
            $this->insert('scm_progress_claims', [
                'code' => $this->code('OPN'), 'subcontract_id' => $subcontract, 'claim_no' => $index + 1,
                'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
                'status' => $status,
            ]);
        }

        // fin — 1 approved bersisa, 1 approved lunas, 1 draft bersisa.
        $customer = $this->insert('crm_customers', ['code' => $this->code('CUST'), 'name' => 'PT Pelanggan']);
        $contract = $this->insert('crm_contracts', ['code' => $this->code('CTR'), 'customer_id' => $customer, 'title' => 'Kontrak', 'scope_type' => 'construction']);
        foreach ([['approved', 100, 0], ['approved', 100, 100], ['draft', 100, 0]] as [$status, $total, $paid]) {
            $this->insert('fin_ar_invoices', [
                'code' => $this->code('INV'), 'customer_id' => $customer, 'contract_id' => $contract,
                'invoice_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
                'description' => 'Termin', 'dpp' => $total, 'total' => $total, 'amount_paid' => $paid,
                'terbilang' => 'seratus rupiah', 'status' => $status,
            ]);
        }

        // hr — 1 submitted, 1 approved.
        $employee = $this->insert('hr_employees', [
            'code' => $this->code('EMP'), 'name' => 'Karyawan', 'nik_ktp' => (string) (3200000000000000 + $this->seq),
            'gender' => 'male', 'birth_date' => '1990-01-01', 'ptkp_status' => 'TK/0', 'join_date' => '2020-01-01',
            'employment_type' => 'tetap', 'position' => 'Staf', 'department' => 'Umum',
        ]);
        foreach (['submitted', 'approved'] as $status) {
            $this->insert('hr_leave_requests', [
                'code' => $this->code('CUTI'), 'employee_id' => $employee, 'leave_type' => 'annual',
                'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
                'day_count' => 2, 'reason' => 'Keperluan keluarga', 'status' => $status,
            ]);
        }

        // svc — 3 belum selesai, 2 selesai.
        foreach (['open', 'assigned', 'in_progress', 'resolved', 'closed'] as $status) {
            $this->insert('svc_tickets', [
                'code' => $this->code('TKT'), 'customer_id' => $customer, 'title' => 'Tiket',
                'reported_at' => now(), 'status' => $status,
            ]);
        }

        // ast — 1 dalam perawatan, 2 tidak.
        $assetCategory = $this->insert('ast_categories', ['code' => $this->code('ACAT'), 'name' => 'Alat Berat']);
        foreach (['maintenance', 'available', 'deployed'] as $status) {
            $this->insert('ast_assets', [
                'code' => $this->code('AST'), 'name' => 'Excavator', 'category_id' => $assetCategory,
                'useful_life_months' => 60, 'acquisition_date' => now()->toDateString(), 'status' => $status,
            ]);
        }

        // iam — 2 job gagal.
        foreach ([1, 2] as $n) {
            DB::table('failed_jobs')->insert([
                'uuid' => "uuid-{$n}-".$this->seq, 'connection' => 'database', 'queue' => 'default',
                'payload' => '{}', 'exception' => 'boom', 'failed_at' => now(),
            ]);
        }
    }
}
