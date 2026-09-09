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
use Tests\Support\FixtureSchema;

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
    /**
     * Modul yang tabelnya menghapus-lembut → [tabel yang harus punya baris
     * dibuang di fixture, angka yang benar SESUDAH baris itu dikecualikan].
     * DB::table melewati scope SoftDeletes, jadi setiap entri memeriksa
     * `deleted_at` dengan tangan — dan aturan yang diperiksa dengan tangan
     * hanya seaman fixture yang punya baris untuk melanggarnya.
     *
     * @var array<string, array{list<string>, int}>
     */
    private const SOFT_DELETED_FIXTURES = [
        'crm' => [['crm_leads'], 3],
        'est' => [['est_boqs'], 2],
        'eng' => [['eng_drawing_submittals'], 2],
        'prj' => [['prj_projects'], 2],
        'qc' => [['qc_ncr'], 2],
        'prc' => [['prc_purchase_orders'], 2],
        'inv' => [['inv_items', 'inv_warehouses'], 8],
        'scm' => [['scm_progress_claims'], 2],
        'fin' => [['fin_ar_invoices'], 2],
        'hr' => [['hr_leave_requests'], 2],
        'svc' => [['svc_tickets'], 4],
        'ast' => [['ast_assets'], 2],
    ];

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

    /**
     * Launcher menulis '—' untuk modul yang izin hitungannya tidak dipegang —
     * dan server, karena itu, TIDAK mengirim entri modul itu, jadi labelnya
     * tidak ada di jawaban. Supaya ubinnya tetap bisa menyebut angka apa yang
     * tidak diketahui ("— Job gagal"), schema.js menyimpan cerminan label ini.
     * Dua daftar yang bisa berselisih hanya aman kalau selisihnya diuji.
     */
    public function test_every_entry_label_is_mirrored_in_the_spa_module_registry(): void
    {
        $source = (string) file_get_contents(public_path('app/js/schema.js'));
        $start = strpos($source, 'export const MODULES = {');
        $this->assertNotFalse($start, 'schema.js tidak punya MODULES; launcher menggambar ubin tanpa aksen maupun nama angka.');
        $block = substr($source, $start, (int) strpos($source, "\n};", $start) - $start);

        preg_match_all("/^  ([a-z]+): \{[^}]*\bkpi: '([^']*)'/m", $block, $matches, PREG_SET_ORDER);
        $mirror = array_column($matches, 2, 1);

        $labels = array_map(fn (array $entry) => $entry['label'], ModuleCounts::entries());
        $this->assertSame($labels, $mirror,
            'Nama angka utama di schema.js MODULES.kpi tidak lagi sama dengan label registri: ubin yang angkanya tidak diketahui akan menyebut angka yang salah.');
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

        /*
         * Angka harapan yang BERBEDA dari jumlah baris status lain mana pun —
         * itulah yang membuat daftar ini menguji status yang dihitung dan bukan
         * hanya "ada kueri". Fixture 1-baris-per-status yang dipakai sampai
         * 6 Sep 2026 lulus untuk status apa pun: 'prc' => 1 benar baik kueri
         * itu menghitung approved, submitted, draft maupun closed, dan empat
         * mutasi status/scope lolos hijau (verifikasi P1-C). Sekarang setiap
         * entri berstatus punya 2 baris yang masuk dan 1 per status yang tidak,
         * jadi penggantian nama status di lane tim lain menjatuhkan uji ini —
         * klaim yang sudah ditulis docblock ModuleCounts sejak awal.
         */
        $this->assertSame([
            'ringkasan' => 2,   // 2 notifikasi belum dibaca; 1 sudah dibaca, 1 milik orang lain
            'crm' => 3,         // new + contacted + qualified; won, lost & yang dibuang tidak
            'est' => 2,         // 2 RAB submitted; 1 draft, 1 approved, 1 dibuang tidak
            'eng' => 2,         // 2 SDS tanpa keputusan & belum disuperseded; 1 diputus, 1 disuperseded, 1 dibuang
            'prj' => 2,         // active + finishing; completed & yang dibuang tidak
            'qc' => 2,          // open + under_correction; verified, closed & yang dibuang tidak
            'prc' => 2,         // 2 PO approved = terbuka; draft/submitted/closed & yang dibuang tidak
            'inv' => 8,         // 8 BARIS gudang×item di bawah AMBANGNYA — dari 6 item; (f) kurang di dua gudang sekaligus, (e)+(g) belum punya baris saldo
            'scm' => 2,         // 2 opname subkon submitted; draft & yang dibuang tidak
            'fin' => 2,         // 2 invoice approved bersisa; lunas, draft & yang dibuang tidak
            'hr' => 2,          // 2 cuti submitted; approved & yang dibuang tidak
            'svc' => 4,         // open + assigned + in_progress + pending_customer; resolved, closed & yang dibuang tidak
            'ast' => 2,         // 2 aset maintenance; available, deployed & yang dibuang tidak
            'iam' => 2,         // 2 job gagal
        ], $counts);
    }

    /**
     * Setiap tabel yang MENGHAPUS-LEMBUT dijaga tangan (DB::table melewati
     * scope SoftDeletes), dan sampai 6 Sep 2026 tidak satu pun baris fixture
     * yang dibuang — jadi menghapus `whereNull('deleted_at')` dari entri mana
     * pun lolos hijau (verifikasi P1-C: mutasi crm). Uji ini memakai fixture
     * yang sama dan menyatakan bagian itu sendirian: buang penjaganya, dan
     * angka yang bergerak dinamai.
     */
    public function test_soft_deleted_rows_are_counted_by_no_entry(): void
    {
        $admin = $this->adminUser();
        $this->seedFixtures($admin);

        $counts = collect(ModuleCounts::for($admin))->pluck('count', 'prefix');

        foreach (self::SOFT_DELETED_FIXTURES as $prefix => [$tables, $withGuard]) {
            foreach ($tables as $table) {
                $this->assertGreaterThan(0, DB::table($table)->whereNotNull('deleted_at')->count(),
                    "Fixture {$prefix} tidak punya satu pun baris {$table} yang dibuang, jadi aturan deleted_at-nya tidak diuji apa pun.");
            }
            $this->assertSame($withGuard, $counts[$prefix],
                "Modul {$prefix} menghitung baris ".implode('/', $tables).' yang sudah dibuang.');
        }
    }

    /**
     * Ubin Sistem bergerbang core.update — izin layar "Antrean Gagal" — dan
     * BUKAN iam.view yang membuka grupnya. Akibatnya disengaja dan dinyatakan
     * di sini dengan literalnya: pemegang hr yang melihat grup Sistem karena
     * iam.view mendapat ubin tanpa angka. test_an_entry_without_its_permission
     * _is_absent_not_zero tidak bisa menjaga ini — ia membaca daftar izin dari
     * registri yang sama, jadi mengganti izin sebuah entri memindahkan kedua
     * sisi pernyataannya (verifikasi P1-C: mutasi core.update → iam.view hijau).
     */
    public function test_the_system_tile_is_gated_by_core_update_not_by_the_group_permission(): void
    {
        $this->assertSame('core.update', ModuleCounts::entries()['iam']['permission']);

        $sees = array_column(ModuleCounts::for($this->userWith(['iam.view'])), 'prefix');
        $this->assertNotContains('iam', $sees,
            'Pemegang iam.view mendapat angka "Job gagal"; gerbangnya harus izin layar antreannya, core.update.');

        $admin = array_column(ModuleCounts::for($this->userWith(['core.update'])), 'prefix');
        $this->assertContains('iam', $admin, 'Pemegang core.update TIDAK mendapat ubin Sistem; gerbangnya menolak semua orang.');
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

    /**
     * …DAN kesetaraan itu harus MEMBEDAKAN sesuatu (F-6).
     *
     * Sampai F-6 kedua kueri hanya membaca `inv_items.min_stock`, jadi uji di
     * atas akan tetap hijau untuk sepasang salinan yang SAMA-SAMA melupakan
     * tabel aturan reorder — yaitu persis kegagalan yang paling mungkin
     * terjadi ketika sebuah aturan baru diterapkan di layanan dan tidak di
     * salinannya. Yang dipaku di sini adalah bahwa fixture-nya benar-benar
     * memisahkan keduanya: kueri "hanya min_stock" di bawah ini adalah kueri
     * SEBELUM F-6, kata demi kata, dan jawabannya HARUS berbeda.
     *
     * Kalau suatu hari fixture-nya berubah sampai kedua angka bertemu lagi,
     * uji ini jatuh dengan menyebutkan sebabnya — bukan diam-diam berhenti
     * menjaga apa pun.
     */
    public function test_the_low_stock_fixture_actually_separates_the_reorder_rule_from_min_stock(): void
    {
        $admin = $this->adminUser();
        $this->seedFixtures($admin);

        $minStockOnly = DB::table('inv_stock_balances as b')
            ->join('inv_items as i', 'i.id', '=', 'b.item_id')
            ->join('inv_warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->whereNull('i.deleted_at')
            ->whereNull('w.deleted_at')
            ->where('i.is_active', true)
            ->where('i.min_stock', '>', 0)
            ->whereColumn('b.qty', '<', 'i.min_stock')
            ->count();

        $withRules = app(StockService::class)->lowStockAlerts()->count();

        $this->assertNotSame(
            $minStockOnly,
            $withRules,
            'Fixture inv tidak lagi memisahkan "dengan aturan reorder" dari "hanya min_stock" (keduanya '
            ."menjawab {$withRules}). Uji kesetaraan registri karena itu tidak membuktikan apa pun tentang "
            .'aturan reorder: sepasang salinan yang sama-sama melupakan inv_reorder_rules akan lolos hijau. '
            .'Kembalikan baris fixture (a)–(e) di seedFixtures().',
        );

        // …dan yang MENANG adalah yang membaca aturan, di kedua permukaan.
        $counts = collect(ModuleCounts::for($admin))->pluck('count', 'prefix');
        $this->assertSame($withRules, $counts['inv']);
    }

    /**
     * Bentuk baris yang dibaca layar dan widget: ambang yang MENANG, angka
     * item yang kalah, dan dari mana ambang itu datang — ketiganya, karena
     * prioritas yang hanya berlaku di kode adalah angka yang tidak bisa
     * diperiksa siapa pun (F-6).
     */
    public function test_a_row_governed_by_a_rule_carries_both_numbers_and_says_which_won(): void
    {
        $admin = $this->adminUser();
        $this->seedFixtures($admin);

        $rows = app(StockService::class)->lowStockAlerts();

        $byRule = $rows->firstWhere('threshold_source', 'rule');
        $this->assertNotNull($byRule, 'Tidak ada satu pun baris yang ambangnya datang dari aturan reorder.');
        $this->assertSame(12.0, (float) $byRule->reorder_point);
        $this->assertSame(0.0, (float) $byRule->min_stock, 'min_stock item yang kalah tetap harus ikut di baris.');
        $this->assertSame('Aturan reorder gudang ini', $byRule->threshold_source_label);
        $this->assertSame(7.0, (float) $byRule->shortage_qty, 'Kekurangan dihitung dari ambang yang MENANG (12 − 5), bukan dari min_stock.');

        $byItem = $rows->firstWhere('threshold_source', 'item');
        $this->assertNotNull($byItem, 'Tidak ada satu pun baris yang ambangnya datang dari min_stock item.');
        $this->assertSame('Stok minimum item', $byItem->threshold_source_label);
        $this->assertSame((float) $byItem->min_stock, (float) $byItem->reorder_point);
    }

    /**
     * ANGKANYA DAN NOUN-nya MENGHITUNG HAL YANG SAMA.
     *
     * Kueri entri 'inv' menghitung BARIS (gudang × item); labelnya dulu
     * berbunyi "Item di bawah titik pesan ulang" dan ubinnya menulis "3 item"
     * untuk 2 item yang kurang di tiga gudang. Orang pengadaan yang membaca
     * "3 item" membuka Usulan Pesan Ulang, menghitung dua nama barang, dan
     * tidak menemukan satu pun kalimat yang menjelaskan selisihnya — dan
     * aturan reorder per gudang adalah fitur yang MEMBUAT selisih itu muncul.
     *
     * ModuleCountsTest memaku kesetaraan KUERI, bukan kesetaraan noun; uji ini
     * yang menutupnya, di atas fixture yang selisihnya nyata.
     */
    public function test_the_inventory_tile_counts_pairs_and_says_pairs(): void
    {
        $admin = $this->adminUser();
        $this->seedFixtures($admin);

        $rows = app(StockService::class)->lowStockAlerts();
        $distinctItems = $rows->pluck('item_id')->unique()->count();

        $this->assertSame(8, $rows->count());
        $this->assertSame(6, $distinctItems,
            'Fixture inv tidak lagi memisahkan BARIS dari ITEM: tanpa satu item yang kurang di dua gudang, '
            .'label yang menghitung yang satu sambil menyebut yang lain tidak bisa dibedakan uji ini.');

        $entry = ModuleCounts::entries()['inv'];
        $count = collect(ModuleCounts::for($admin))->firstWhere('prefix', 'inv')['count'];

        $this->assertSame($rows->count(), $count, 'Ubin menghitung baris, bukan item.');
        $this->assertNotSame($distinctItems, $count);

        // …jadi labelnya harus menyebut PASANGAN, dan satuannya bukan "item".
        $this->assertStringContainsString('Pasangan gudang × item', $entry['label'],
            "Label ubin \"{$entry['label']}\" menyebut satuan yang berbeda dari yang dihitung kuerinya.");
        $this->assertNotSame('item', $entry['unit'],
            'Satuan "item" pada angka yang menghitung baris membuat ubin menulis "6 item" untuk 5 item.');
    }

    // ------------------------------------------------------------- degradasi

    /**
     * DAFTAR `tables` ADALAH SATU-SATUNYA HAL YANG DIBACA Schema::hasTable —
     * dan sampai uji ini ada, ia dijaga oleh assertNotEmpty saja.
     *
     * Membuang satu nama tabel dari daftar sebuah entri lolos seluruh suite
     * Core hijau. Akibatnya bukan angka yang salah melainkan DEGRADASI KELAS
     * DUA: pada jendela deploy sebelum migrasinya jalan, entri yang seharusnya
     * DIAM ABSEN justru hadir dengan count NULL, dan setiap pembukaan launcher
     * oleh setiap pengguna menuliskan peringatan di log seolah ada yang rusak.
     *
     * Yang dipaku di sini bukan satu tabel melainkan HUBUNGANNYA: setiap tabel
     * yang kueri entri benar-benar sentuh harus muncul di `tables`. Ia tumbuh
     * sendiri bersama registri, tanpa daftar kedua yang bisa menyimpang.
     */
    public function test_every_table_an_entry_queries_is_declared_in_the_list_that_guards_it(): void
    {
        $admin = $this->adminUser();

        foreach (ModuleCounts::entries() as $prefix => $entry) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            try {
                ($entry['count'])($admin);
            } finally {
                $sql = implode(' ; ', array_column(DB::getQueryLog(), 'query'));
                DB::disableQueryLog();
            }

            preg_match_all('/\b(?:from|join)\s+[`"]?([a-z][a-z0-9_]*)[`"]?/i', $sql, $matches);
            $touched = array_values(array_unique($matches[1]));

            $this->assertNotEmpty($touched, "Kueri entri {$prefix} tidak menyentuh satu tabel pun — kuerinya tidak berjalan.");

            foreach ($touched as $table) {
                $this->assertContains($table, $entry['tables'],
                    "Entri {$prefix} membaca `{$table}` tetapi tidak menyebutnya di `tables`, jadi Schema::hasTable "
                    .'tidak menjaganya: pada jendela deploy sebelum migrasinya jalan, ubinnya hadir dengan angka NULL '
                    .'dan sebuah peringatan di log, bukan diam absen seperti yang registri janjikan.');
            }
        }
    }

    /**
     * …dan bentuk konkretnya untuk tabel yang F-6 tambahkan: tanpa
     * `inv_reorder_rules`, entri Persediaan harus ABSEN, bukan hadir ber-NULL.
     */
    public function test_the_inventory_entry_falls_silent_when_the_reorder_rule_table_is_not_there_yet(): void
    {
        $this->skipUnlessTransactionalDdl();

        $admin = $this->adminUser();
        $this->assertContains('inv', array_column(ModuleCounts::for($admin), 'prefix'));

        Log::spy();

        FixtureSchema::withMissingTable('inv_reorder_rules', function () use ($admin): void {
            ModuleCounts::flushSchemaMemo();
            $prefixes = array_column(ModuleCounts::for($admin), 'prefix');

            $this->assertNotContains('inv', $prefixes,
                'Entri Persediaan melapor angka tanpa tabel aturan reorder yang kuerinya join.');
            $this->assertContains('prc', $prefixes, '…dan modul lain tidak ikut hilang.');
        });

        ModuleCounts::flushSchemaMemo();

        // Diam ABSEN, bukan "hadir tetapi rusak": tidak ada peringatan yang
        // ditulis untuk tabel yang memang belum dimigrasikan.
        Log::shouldNotHaveReceived('warning');
    }

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

    /**
     * Tiga hitungan registri yang dulu memindai seluruh tabel punya indeksnya.
     *
     * EXPLAIN keempat belas kueri di MySQL 8 (verifikasi P1-C putaran 2)
     * menemukan `type=ALL key=NULL` pada qc_ncr.status, hr_leave_requests.status
     * dan eng_drawing_submittals(decision, superseded_at) — dan sejak P1-C
     * ketiganya berjalan setiap kali launcher #/home dibuka, yaitu landing
     * ponsel setiap pengguna. Migrasi 000196 menambahkannya; uji ini menjaga
     * agar tidak hilang lagi tanpa ada yang menyadarinya.
     */
    public function test_the_scanning_counts_have_their_indexes(): void
    {
        foreach ([
            'qc_ncr' => 'qc_ncr_status_index',
            'hr_leave_requests' => 'hr_leave_requests_status_index',
            'eng_drawing_submittals' => 'eng_drawing_submittals_decision_superseded_index',
        ] as $table => $index) {
            $names = array_map(fn (array $one) => $one['name'] ?? '', Schema::getIndexes($table));

            $this->assertContains($index, $names,
                "{$table} kehilangan indeks {$index}: hitungan registrinya kembali memindai seluruh tabel, "
                .'dan hitungan itu berjalan setiap kali launcher dibuka.');
        }
    }

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

        // Daftar, bukan substring: `notmodules` bukan `modules`, dan sampai
        // 6 Sep 2026 str_contains() membuat permintaan itu membayar 14 hitungan
        // yang tidak diminta siapa pun (verifikasi P1-C putaran 2).
        foreach (['notmodules', 'modules-lain', 'MODULES', 'module'] as $near) {
            $this->getJson("/api/core/dashboard/summary?include={$near}")
                ->assertOk()
                ->assertJsonMissingPath('data.modules');
        }

        // …tetapi anggota daftar yang sah tetap dibaca, di mana pun letaknya.
        foreach (['a,modules', 'modules,a', ' modules ', 'a, modules ,b'] as $list) {
            $this->getJson('/api/core/dashboard/summary?include='.rawurlencode($list))
                ->assertOk()
                ->assertJsonStructure(['data' => ['modules']]);
        }
    }

    public function test_an_array_shaped_include_parameter_is_read_like_the_string_form(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum');

        // `?include[]=modules` membuat query() mengembalikan array. Dulu is_string()
        // menolaknya (agar cast (string) tidak meng-500-kan dasbor lewat tautan
        // karangan) sehingga permintaan yang jelas-jelas meminta blok itu dijawab
        // TANPA blok; kini kedua bentuk dibaca dengan satu aturan dan tetap tidak
        // pernah 500.
        $this->getJson('/api/core/dashboard/summary?include[]=modules')
            ->assertOk()
            ->assertJsonStructure(['data' => ['modules']]);

        $this->getJson('/api/core/dashboard/summary?include[]=notmodules')
            ->assertOk()
            ->assertJsonMissingPath('data.modules');

        // Array bersarang: (string) atas array adalah 500 — kini tidak pernah tercapai.
        $this->getJson('/api/core/dashboard/summary?include[][]=modules')
            ->assertOk()
            ->assertJsonMissingPath('data.modules');
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
     * Fixture per modul dengan TIGA sifat, dan ketiganya baru sejak verifikasi
     * P1-C (6 Sep 2026) karena versi sebelumnya — satu baris per status —
     * meloloskan empat mutasi status/scope:
     *
     *  - LEBIH BANYAK YANG MASUK DARIPADA YANG TIDAK. Dua baris berstatus yang
     *    dihitung, satu per status yang tidak, jadi angka harapannya (2) salah
     *    untuk status mana pun yang lain. Dengan satu baris per status, 'prc'
     *    => 1 benar entah kuerinya menghitung approved, submitted, draft
     *    maupun closed.
     *  - SATU BARIS YANG DIBUANG di setiap tabel yang menghapus-lembut, supaya
     *    `whereNull('deleted_at')` yang hilang menggerakkan angka.
     *  - STATUS YANG DIPERDEBATKAN ADA BARISNYA. svc menghitung
     *    pending_customer ("tiket yang menunggu pelanggan tetap milik kita"),
     *    dan sampai putaran ini tidak ada satu pun tiket pending_customer di
     *    seluruh fixture — argumen yang ditulis registrinya sendiri tidak
     *    diuji apa pun.
     */
    private function seedFixtures(User $admin): void
    {
        // ringkasan — 2 belum dibaca, 1 sudah, 1 milik orang lain.
        $other = $this->insert('users', ['name' => 'Orang Lain', 'email' => 'lain@test.local', 'password' => 'x', 'is_active' => true]);
        foreach ([null, null, now()] as $readAt) {
            $this->insert('core_notifications', ['user_id' => $admin->id, 'event' => 'document.submitted', 'title' => 'Uji', 'read_at' => $readAt]);
        }
        $this->insert('core_notifications', ['user_id' => $other, 'event' => 'document.submitted', 'title' => 'Uji', 'read_at' => null]);

        // crm — 3 terbuka, 2 selesai, 1 terbuka yang sudah dibuang.
        foreach (['new', 'contacted', 'qualified', 'won', 'lost'] as $status) {
            $this->insert('crm_leads', ['code' => $this->code('LEAD'), 'name' => 'Prospek', 'status' => $status]);
        }
        $this->insert('crm_leads', ['code' => $this->code('LEAD'), 'name' => 'Prospek dibuang', 'status' => 'new', 'deleted_at' => now()]);

        // est — 2 submitted, 1 draft, 1 approved, 1 submitted yang dibuang.
        foreach (['submitted', 'submitted', 'draft', 'approved'] as $status) {
            $this->insert('est_boqs', ['code' => $this->code('BOQ'), 'title' => 'RAB', 'status' => $status]);
        }
        $this->insert('est_boqs', ['code' => $this->code('BOQ'), 'title' => 'RAB dibuang', 'status' => 'submitted', 'deleted_at' => now()]);

        // prj — 2 aktif (active + finishing), 1 selesai, 1 aktif yang dibuang.
        $projects = [];
        foreach (['active', 'finishing', 'completed'] as $status) {
            $projects[$status] = $this->insert('prj_projects', ['code' => $this->code('PRJ'), 'name' => 'Proyek', 'type' => 'construction', 'status' => $status]);
        }
        $this->insert('prj_projects', ['code' => $this->code('PRJ'), 'name' => 'Proyek dibuang', 'type' => 'construction', 'status' => 'active', 'deleted_at' => now()]);

        // eng — 2 menunggu keputusan, 1 sudah diputus, 1 sudah disuperseded, 1 dibuang.
        $drawing = $this->insert('eng_drawings', ['project_id' => $projects['active'], 'number' => $this->code('DWG'), 'title' => 'Denah', 'discipline' => 'structure']);
        // Revisi berbeda per baris: UNIQUE(drawing_id, revision) di register gambar.
        foreach ([[null, null, null], [null, null, null], ['approved', null, null], [null, now(), null], [null, null, now()]] as $index => [$decision, $superseded, $deleted]) {
            $this->insert('eng_drawing_submittals', [
                'code' => $this->code('SDS'), 'drawing_id' => $drawing, 'revision' => "R{$index}",
                'submitted_at' => now()->toDateString(), 'reviewer_party' => 'mk',
                'decision' => $decision, 'superseded_at' => $superseded, 'deleted_at' => $deleted,
            ]);
        }

        // qc — 2 terbuka (open + under_correction), 2 selesai, 1 terbuka yang dibuang.
        $location = $this->insert('core_locations', ['project_id' => $projects['active'], 'kind' => 'zone', 'code' => $this->code('LOC'), 'name' => 'Zona A']);
        foreach (['open', 'under_correction', 'verified', 'closed'] as $status) {
            $this->insert('qc_ncr', [
                'code' => $this->code('NCR'), 'project_id' => $projects['active'], 'location_id' => $location,
                'stage' => 'pelaksanaan', 'description' => 'Tidak sesuai', 'status' => $status,
            ]);
        }
        $this->insert('qc_ncr', [
            'code' => $this->code('NCR'), 'project_id' => $projects['active'], 'location_id' => $location,
            'stage' => 'pelaksanaan', 'description' => 'Dibuang', 'status' => 'open', 'deleted_at' => now(),
        ]);

        // prc — 2 terbuka (approved), 3 bukan, 1 approved yang dibuang; `closed`
        // adalah PO yang barangnya sudah lengkap.
        $vendor = $this->insert('prc_vendors', ['code' => $this->code('VND'), 'name' => 'PT Uji', 'classification' => 'supplier']);
        foreach (['approved', 'approved', 'draft', 'submitted', 'closed'] as $status) {
            $this->insert('prc_purchase_orders', ['code' => $this->code('PO'), 'vendor_id' => $vendor, 'order_date' => now()->toDateString(), 'status' => $status]);
        }
        $this->insert('prc_purchase_orders', [
            'code' => $this->code('PO'), 'vendor_id' => $vendor, 'order_date' => now()->toDateString(),
            'status' => 'approved', 'deleted_at' => now(),
        ]);

        // inv — 4 baris di bawah AMBANGNYA; di atas ambang, item nonaktif, item
        // dibuang dan gudang dibuang semuanya tidak dihitung.
        $category = $this->insert('inv_item_categories', ['code' => $this->code('CAT'), 'name' => 'Semen']);
        $warehouse = $this->insert('inv_warehouses', ['code' => $this->code('WH'), 'name' => 'Gudang']);
        // Tiga baris terakhir duduk PERSIS di batas kedua perbandingan numerik entri
        // inv, yang tanpa mereka lolos mutasi (verifikasi P1-C putaran 2):
        //  - qty == min membuktikan `qty < min_stock`, bukan `<=`;
        //  - min_stock 0 dengan qty 0 adalah kasus lazimnya (item tanpa ambang);
        //  - min_stock 0 dengan qty NEGATIF membuktikan `min_stock > 0` sendiri.
        //    Saldo negatif tidak pernah dibuat StockService (setiap jalur menolak
        //    qty <= 0 dan uji burst mengukur "stok tak pernah negatif"), tetapi
        //    kolomnya decimal(15,3) tanpa unsigned, jadi keadaan itu BISA ada —
        //    entah dari koreksi opname atau data lama. Aturannya: item yang tidak
        //    menyatakan stok minimum tidak punya ambang, jadi ia tidak pernah "di
        //    bawah minimum" berapa pun saldonya. Tanpa baris ini `> 0` boleh
        //    menjadi `>= 0` tanpa satu uji pun berubah warna.
        foreach ([[true, 10, 2, null], [true, 10, 40, null], [false, 10, 1, null], [true, 10, 1, now()],
            [true, 10, 10, null], [true, 0, 0, null], [true, 0, -1, null]] as [$active, $min, $qty, $deleted]) {
            $item = $this->insert('inv_items', [
                'code' => $this->code('ITM'), 'name' => 'Item', 'category_id' => $category,
                'unit' => 'sak', 'min_stock' => $min, 'is_active' => $active, 'deleted_at' => $deleted,
            ]);
            $this->insert('inv_stock_balances', ['warehouse_id' => $warehouse, 'item_id' => $item, 'qty' => $qty]);
        }
        // …dan gudang yang dibuang membawa serta barisnya, walau itemnya hidup.
        $closedWarehouse = $this->insert('inv_warehouses', ['code' => $this->code('WH'), 'name' => 'Gudang dibuang', 'deleted_at' => now()]);
        $liveItem = $this->insert('inv_items', ['code' => $this->code('ITM'), 'name' => 'Item', 'category_id' => $category, 'unit' => 'sak', 'min_stock' => 10, 'is_active' => true]);
        $this->insert('inv_stock_balances', ['warehouse_id' => $closedWarehouse, 'item_id' => $liveItem, 'qty' => 1]);

        /*
         * F-6 — LIMA BARIS YANG JAWABANNYA BERBEDA ANTARA "DENGAN ATURAN
         * REORDER" DAN "HANYA min_stock", supaya salinan kueri di registri ini
         * tidak bisa melupakan join-nya dan tetap hijau. Tanpa kelimanya, uji
         * kesetaraan di bawah hanya membandingkan dua kueri yang kebetulan
         * sama-sama mengabaikan tabel aturan.
         *
         *  (a) min 0, qty 5, aturan aktif titik 12 → MASUK karena aturannya.
         *      min_stock sendiri berkata "tidak punya ambang".
         *  (b) min 100, qty 50, aturan aktif titik 20 → KELUAR karena
         *      aturannya MENGGANTIKAN, bukan mengambil yang paling ketat.
         *      Sebuah GREATEST()/max() di kueri mana pun menjatuhkan baris ini.
         *  (c) min 100, qty 50, aturan NONAKTIF titik 20 → MASUK: aturan mati
         *      tidak menentukan apa pun, angka item berlaku lagi.
         *  (d) min 5, qty 3, aturan aktif titik 0 → KELUAR: titik 0 pada
         *      aturan aktif berarti "pasangan ini tidak pernah dipesan ulang",
         *      jadi syarat "> 0" berlaku pada ambang yang MENANG, bukan pada
         *      min_stock.
         *  (e) min 100, qty 50, aturan aktif titik 20 di GUDANG LAIN → MASUK:
         *      aturan milik pasangan, bukan milik item. Sebuah join yang lupa
         *      warehouse_id menjatuhkan baris ini. Sejak putaran ketiga F-6 ia
         *      menyumbang DUA baris: pasangan bersaldo di gudang pertama lewat
         *      min_stock-nya, dan pasangan di GUDANG LAIN — yang aturannya
         *      menyatakan gudang itu menyimpan barang ini dan yang belum punya
         *      satu baris saldo pun — lewat lengan kedua.
         */
        $otherWarehouse = $this->insert('inv_warehouses', ['code' => $this->code('WH'), 'name' => 'Gudang lain']);
        foreach ([
            ['min' => 0, 'qty' => 5, 'point' => 12, 'active' => true, 'where' => 'same'],
            ['min' => 100, 'qty' => 50, 'point' => 20, 'active' => true, 'where' => 'same'],
            ['min' => 100, 'qty' => 50, 'point' => 20, 'active' => false, 'where' => 'same'],
            ['min' => 5, 'qty' => 3, 'point' => 0, 'active' => true, 'where' => 'same'],
            ['min' => 100, 'qty' => 50, 'point' => 20, 'active' => true, 'where' => 'other'],
        ] as $case) {
            $item = $this->insert('inv_items', [
                'code' => $this->code('ITM'), 'name' => 'Item aturan', 'category_id' => $category,
                'unit' => 'sak', 'min_stock' => $case['min'], 'is_active' => true,
            ]);
            $this->insert('inv_stock_balances', ['warehouse_id' => $warehouse, 'item_id' => $item, 'qty' => $case['qty']]);
            $this->insert('inv_reorder_rules', [
                'warehouse_id' => $case['where'] === 'same' ? $warehouse : $otherWarehouse,
                'item_id' => $item,
                'reorder_point' => $case['point'],
                'reorder_qty' => 0,
                'is_active' => $case['active'],
            ]);
        }

        /*
         * (f) SATU ITEM YANG KURANG DI DUA GUDANG — dua BARIS, satu ITEM.
         *
         * Tanpa baris ini fixture inv tidak pernah membedakan "berapa baris"
         * dari "berapa item", dan label ubin boleh menghitung yang satu sambil
         * menyebut yang lain tanpa satu uji pun berubah warna. Itulah persis
         * yang terjadi sebelum F-6 diperbaiki: ubin berbunyi "3 item" untuk 2
         * item yang kurang di tiga gudang.
         */
        $twoWarehouseItem = $this->insert('inv_items', [
            'code' => $this->code('ITM'), 'name' => 'Item dua gudang', 'category_id' => $category,
            'unit' => 'sak', 'min_stock' => 50, 'is_active' => true,
        ]);
        $this->insert('inv_stock_balances', ['warehouse_id' => $warehouse, 'item_id' => $twoWarehouseItem, 'qty' => 10]);
        $this->insert('inv_stock_balances', ['warehouse_id' => $otherWarehouse, 'item_id' => $twoWarehouseItem, 'qty' => 20]);

        /*
         * (g) SATU PASANGAN YANG BELUM PUNYA BARIS SALDO SAMA SEKALI.
         *
         * Kueri kekurangan punya lengan KEDUA sejak putaran ketiga F-6: sebuah
         * aturan hidup ADALAH pernyataan "gudang ini menyimpan barang ini",
         * dan pasangan yang belum pernah kemasukan barang punya stok nol —
         * yaitu keadaan yang paling membutuhkan pesan ulang. Tanpa baris
         * fixture ini, salinan registri boleh melupakan lengan kedua itu dan
         * tetap hijau, yang adalah persis kegagalan yang uji kesetaraan di
         * berkas ini dibuat untuk menangkap.
         *
         * Baris kedua di bawah (aturan titik 0) adalah pasangannya yang tetap
         * DIAM: syarat "> 0" berlaku di lengan kedua juga.
         */
        $neverStocked = $this->insert('inv_items', [
            'code' => $this->code('ITM'), 'name' => 'Item belum pernah masuk', 'category_id' => $category,
            'unit' => 'sak', 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->insert('inv_reorder_rules', [
            'warehouse_id' => $warehouse, 'item_id' => $neverStocked,
            'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => true,
        ]);
        $neverStockedZero = $this->insert('inv_items', [
            'code' => $this->code('ITM'), 'name' => 'Item belum pernah masuk, titik 0', 'category_id' => $category,
            'unit' => 'sak', 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->insert('inv_reorder_rules', [
            'warehouse_id' => $warehouse, 'item_id' => $neverStockedZero,
            'reorder_point' => 0, 'reorder_qty' => 0, 'is_active' => true,
        ]);

        // scm — 2 opname submitted, 1 draft, 1 submitted yang dibuang.
        $subcontract = $this->insert('scm_subcontracts', ['code' => $this->code('SPK'), 'vendor_id' => $vendor, 'title' => 'Pekerjaan', 'pph_scheme' => 'final_2_65']);
        foreach (['submitted', 'submitted', 'draft', 'submitted'] as $index => $status) {
            $this->insert('scm_progress_claims', [
                'code' => $this->code('OPN'), 'subcontract_id' => $subcontract, 'claim_no' => $index + 1,
                'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
                'status' => $status, 'deleted_at' => $index === 3 ? now() : null,
            ]);
        }

        // fin — 2 approved bersisa, 1 approved lunas, 1 draft bersisa, 1 approved
        // bersisa yang dibuang.
        $customer = $this->insert('crm_customers', ['code' => $this->code('CUST'), 'name' => 'PT Pelanggan']);
        $contract = $this->insert('crm_contracts', ['code' => $this->code('CTR'), 'customer_id' => $customer, 'title' => 'Kontrak', 'scope_type' => 'construction']);
        foreach ([['approved', 100, 0, null], ['approved', 100, 40, null], ['approved', 100, 100, null], ['draft', 100, 0, null], ['approved', 100, 0, now()]] as [$status, $total, $paid, $deleted]) {
            $this->insert('fin_ar_invoices', [
                'code' => $this->code('INV'), 'customer_id' => $customer, 'contract_id' => $contract,
                'invoice_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
                'description' => 'Termin', 'dpp' => $total, 'total' => $total, 'amount_paid' => $paid,
                'terbilang' => 'seratus rupiah', 'status' => $status, 'deleted_at' => $deleted,
            ]);
        }

        // hr — 2 submitted, 1 approved, 1 submitted yang dibuang.
        $employee = $this->insert('hr_employees', [
            'code' => $this->code('EMP'), 'name' => 'Karyawan', 'nik_ktp' => (string) (3200000000000000 + $this->seq),
            'gender' => 'male', 'birth_date' => '1990-01-01', 'ptkp_status' => 'TK/0', 'join_date' => '2020-01-01',
            'employment_type' => 'tetap', 'position' => 'Staf', 'department' => 'Umum',
        ]);
        foreach ([['submitted', null], ['submitted', null], ['approved', null], ['submitted', now()]] as [$status, $deleted]) {
            $this->insert('hr_leave_requests', [
                'code' => $this->code('CUTI'), 'employee_id' => $employee, 'leave_type' => 'annual',
                'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
                'day_count' => 2, 'reason' => 'Keperluan keluarga', 'status' => $status, 'deleted_at' => $deleted,
            ]);
        }

        // svc — 4 belum selesai (pending_customer IKUT: tiket yang menunggu
        // pelanggan tetap milik kita), 2 selesai, 1 terbuka yang dibuang.
        foreach ([['open', null], ['assigned', null], ['in_progress', null], ['pending_customer', null], ['resolved', null], ['closed', null], ['open', now()]] as [$status, $deleted]) {
            $this->insert('svc_tickets', [
                'code' => $this->code('TKT'), 'customer_id' => $customer, 'title' => 'Tiket',
                'reported_at' => now(), 'status' => $status, 'deleted_at' => $deleted,
            ]);
        }

        // ast — 2 dalam perawatan, 2 tidak, 1 dalam perawatan yang dibuang.
        $assetCategory = $this->insert('ast_categories', ['code' => $this->code('ACAT'), 'name' => 'Alat Berat']);
        foreach ([['maintenance', null], ['maintenance', null], ['available', null], ['deployed', null], ['maintenance', now()]] as [$status, $deleted]) {
            $this->insert('ast_assets', [
                'code' => $this->code('AST'), 'name' => 'Excavator', 'category_id' => $assetCategory,
                'useful_life_months' => 60, 'acquisition_date' => now()->toDateString(), 'status' => $status,
                'deleted_at' => $deleted,
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
