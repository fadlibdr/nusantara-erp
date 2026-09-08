<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Inventory\Models\ReorderRule;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Services\PurchaseRequisitionService;
use Modules\Projects\Models\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * Usulan PR dari kekurangan stok (F-6).
 *
 * Yang diuji di sini bukan "apakah sebuah PR dibuat" melainkan tiga janji yang
 * kalau dilanggar merugikan uang orang:
 *
 *  - PR-nya DRAF, selalu, dan tidak ada jalan dari layar ini ke Diajukan atau
 *    Disetujui;
 *  - menjalankan usulan dua kali tidak membuat permintaan kedua untuk
 *    kekurangan yang sama, dan yang dilewati DIKATAKAN beserta kode PR-nya;
 *  - jumlah yang diusulkan datang dari ambang yang MENANG, bukan dari
 *    min_stock item yang mungkin sudah digantikan aturan gudang.
 */
class ReorderProposalTest extends ErpTestCase
{
    use InventoryFixtures;

    private function balance(int $warehouseId, int $itemId, float $qty): void
    {
        StockBalance::query()->create([
            'warehouse_id' => $warehouseId, 'item_id' => $itemId, 'qty' => $qty, 'avg_cost' => 1000,
        ]);
    }

    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('penjaga-gudang', 'web');
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Penjaga Gudang', 'email' => 'gudang@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** Satu gudang, satu item kurang 7 dari titik pesan ulang 12. */
    private function oneShortage(): array
    {
        $warehouse = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Kabel UTP Cat6', ['min_stock' => 0, 'unit' => 'roll', 'last_price' => 1150000]);
        $this->balance($warehouse->id, $item->id, 5);
        ReorderRule::create([
            'warehouse_id' => $warehouse->id, 'item_id' => $item->id,
            'reorder_point' => 12, 'reorder_qty' => 0, 'is_active' => true,
        ]);

        return [$warehouse, $item];
    }

    public function test_the_proposal_reads_and_stores_nothing(): void
    {
        [$warehouse, $item] = $this->oneShortage();

        $payload = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $payload['rules']['proposable']);
        $this->assertSame(0, $payload['rules']['skipped']);
        $this->assertSame($item->id, $payload['rows'][0]['item_id']);
        $this->assertSame(7.0, (float) $payload['rows'][0]['suggested_qty']);
        $this->assertSame('rule', $payload['rows'][0]['threshold_source']);
        $this->assertFalse($payload['rows'][0]['skipped']);
        $this->assertNotEmpty($payload['why_skipped'], 'Aturan pelewatan harus datang dari server sebagai kalimat.');

        $this->assertSame(0, PurchaseRequisition::query()->count(), 'Membaca usulan tidak boleh membuat dokumen apa pun.');
        $this->assertSame($warehouse->id, $payload['rows'][0]['warehouse_id']);
    }

    public function test_creating_makes_a_draft_requisition_and_never_submits_it(): void
    {
        [$warehouse, $item] = $this->oneShortage();

        $payload = $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('api/inventory/reorder/requisitions', [])
            ->assertCreated()
            ->json('data');

        $this->assertCount(1, $payload['created']);
        $this->assertSame(DocumentStatus::Draft->value, $payload['created'][0]['status']);

        $pr = PurchaseRequisition::query()->with('items')->firstOrFail();
        $this->assertSame(DocumentStatus::Draft, $pr->status);
        $this->assertSame($warehouse->id, $pr->warehouse_id);
        $this->assertCount(1, $pr->items);
        $this->assertSame($item->id, $pr->items[0]->item_id);
        $this->assertSame('7.000', $pr->items[0]->qty);
        $this->assertSame('roll', $pr->items[0]->unit);
        // Taksiran harga = harga beli terakhir dari kartu item, bukan angka
        // yang dikarang di sini.
        $this->assertSame('1150000.00', $pr->items[0]->estimated_price);
        // Barisnya membawa angkanya sendiri ke atas kertas.
        $this->assertStringContainsString('titik pesan ulang 12', (string) $pr->items[0]->description);
    }

    /**
     * IDEMPOTENSI — perangkap utama paket ini. Menjalankan usulan dua kali
     * tidak boleh menghasilkan PR draf kedua untuk kekurangan yang sama.
     */
    public function test_running_the_proposal_twice_does_not_raise_a_second_requisition(): void
    {
        $this->oneShortage();
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')->postJson('api/inventory/reorder/requisitions', [])->assertCreated();

        $second = $this->actingAs($admin, 'sanctum')
            ->postJson('api/inventory/reorder/requisitions', [])
            ->assertOk()
            ->json('data');

        $this->assertSame([], $second['created']);
        $this->assertSame(1, PurchaseRequisition::query()->count());
        $this->assertStringContainsString('sudah ada di PR atau PO terbuka', $second['message']);
    }

    /** …dan layar MENGATAKAN mengapa, dengan kode PR yang menahannya. */
    public function test_the_skipped_row_names_the_requisition_that_covers_it(): void
    {
        $this->oneShortage();
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')->postJson('api/inventory/reorder/requisitions', [])->assertCreated();
        $code = PurchaseRequisition::query()->value('code');

        $row = $this->actingAs($admin, 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk()
            ->json('data.rows.0');

        $this->assertTrue($row['skipped']);
        $this->assertSame($code, $row['skipped_requisition_code']);
        $this->assertStringContainsString($code, (string) $row['skipped_reason']);
        $this->assertStringContainsString('Draf', (string) $row['skipped_reason']);
    }

    /**
     * PR yang DITOLAK tidak menahan apa pun: seseorang menolak permintaan itu,
     * dan mengusulkannya lagi justru yang benar. Tanpa lengan ini, satu
     * penolakan membuat barang itu tidak pernah diusulkan lagi selamanya.
     */
    public function test_a_rejected_requisition_does_not_block_the_item_forever(): void
    {
        [$warehouse, $item] = $this->oneShortage();

        $pr = app(PurchaseRequisitionService::class)->create([
            'warehouse_id' => $warehouse->id,
            'items' => [['item_id' => $item->id, 'qty' => 7, 'unit' => 'roll']],
        ]);
        $pr->forceFill(['status' => DocumentStatus::Rejected])->save();

        $row = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk()
            ->json('data.rows.0');

        $this->assertFalse($row['skipped']);
    }

    /**
     * Sebuah PR terbuka untuk GUDANG LAIN tidak menutup kekurangan gudang ini
     * — barangnya dikirim ke tempat lain.
     */
    public function test_an_open_requisition_for_another_warehouse_does_not_cover_this_one(): void
    {
        [$site, $item] = $this->oneShortage();
        $central = $this->makeWarehouse('GD-PUSAT');

        app(PurchaseRequisitionService::class)->create([
            'warehouse_id' => $central->id,
            'items' => [['item_id' => $item->id, 'qty' => 100, 'unit' => 'roll']],
        ]);

        $row = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk()
            ->json('data.rows.0');

        $this->assertFalse($row['skipped']);
        $this->assertSame($site->id, $row['warehouse_id']);
    }

    /**
     * …tetapi sebuah PR terbuka yang TIDAK MENYEBUT gudang menahannya, dan itu
     * pilihan yang dinyatakan: ia mungkin memang untuk gudang ini, dan
     * memesan barang dua kali hanya terlihat setelah barangnya datang.
     */
    public function test_an_open_requisition_without_a_warehouse_covers_the_shortage_and_says_so(): void
    {
        [, $item] = $this->oneShortage();

        app(PurchaseRequisitionService::class)->create([
            'items' => [['item_id' => $item->id, 'qty' => 100, 'unit' => 'roll']],
        ]);

        $row = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk()
            ->json('data.rows.0');

        $this->assertTrue($row['skipped']);
        $this->assertStringContainsString('tidak menyebut gudang', (string) $row['skipped_reason']);
    }

    /** Dua gudang = dua PR, karena satu PR punya satu gudang tujuan. */
    public function test_two_warehouses_get_one_draft_requisition_each(): void
    {
        $site = $this->makeWarehouse('GD-SITE');
        $central = $this->makeWarehouse('GD-PUSAT');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 100, 'unit' => 'zak']);
        $this->balance($site->id, $item->id, 10);
        $this->balance($central->id, $item->id, 20);

        $payload = $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('api/inventory/reorder/requisitions', [])
            ->assertCreated()
            ->json('data');

        $this->assertCount(2, $payload['created']);
        $this->assertEqualsCanonicalizing(
            [$central->id, $site->id],
            collect($payload['created'])->pluck('warehouse_id')->all(),
        );
    }

    /** Satu gudang saja bila diminta begitu — sisanya tetap tak tersentuh. */
    public function test_the_warehouse_filter_narrows_what_is_proposed(): void
    {
        $site = $this->makeWarehouse('GD-SITE');
        $central = $this->makeWarehouse('GD-PUSAT');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 100, 'unit' => 'zak']);
        $this->balance($site->id, $item->id, 10);
        $this->balance($central->id, $item->id, 20);

        $payload = $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('api/inventory/reorder/requisitions', ['warehouse_id' => $site->id])
            ->assertCreated()
            ->json('data');

        $this->assertCount(1, $payload['created']);
        $this->assertSame($site->id, $payload['created'][0]['warehouse_id']);
    }

    /**
     * Proyek PR DITURUNKAN dari gudangnya, tidak ditebak: gudang site membawa
     * project_id-nya sendiri, gudang pusat tidak punya satu pun dan barisnya
     * tetap kosong.
     */
    public function test_a_site_warehouse_carries_its_project_onto_the_requisition(): void
    {
        $project = Project::query()->create([
            'code' => 'PRJ-2026-777', 'name' => 'Gedung Uji', 'type' => 'construction', 'status' => 'active',
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'GD-SITE777', 'name' => 'Gudang Site 777', 'is_active' => true, 'project_id' => $project->id,
        ]);
        $item = $this->makeItem('Semen Portland', ['min_stock' => 100, 'unit' => 'zak']);
        $this->balance($warehouse->id, $item->id, 10);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('api/inventory/reorder/requisitions', [])
            ->assertCreated();

        $this->assertSame($project->id, PurchaseRequisition::query()->value('project_id'));
    }

    /**
     * Membuat PR adalah tindakan Procurement, dari layar mana pun tombolnya
     * ditekan. Seorang penjaga gudang yang memegang seluruh inv.* dan bukan
     * prc.create tidak boleh bisa menerbitkan permintaan pembelian.
     */
    public function test_creating_requires_prc_create_not_merely_inventory_rights(): void
    {
        $this->oneShortage();
        $warehouseman = $this->userWith(['inv.view', 'inv.create', 'inv.update', 'inv.post']);

        $this->actingAs($warehouseman, 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk();

        $this->actingAs($warehouseman, 'sanctum')
            ->postJson('api/inventory/reorder/requisitions', [])
            ->assertForbidden();

        $this->assertSame(0, PurchaseRequisition::query()->count());
    }

    /**
     * "SEMUANYA SUDAH ADA DI PR TERBUKA" DIKATAKAN UNTUK SEBAB YANG BERBEDA.
     *
     * Sebuah gudang yang memang tidak punya satu pun kekurangan membaca
     * kalimat yang menyuruhnya mencari PR yang tidak pernah ada. Servernya
     * MEMEGANG angka yang membantah kalimatnya sendiri: skipped = 0 berarti
     * tidak ada apa pun yang ada di PR terbuka.
     */
    public function test_a_warehouse_with_nothing_below_its_threshold_is_not_told_to_look_for_a_requisition(): void
    {
        $warehouse = $this->makeWarehouse('GD-KOSONG');

        $payload = $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('api/inventory/reorder/requisitions', ['warehouse_id' => $warehouse->id])
            ->assertOk()
            ->json('data');

        $this->assertSame([], $payload['created']);
        $this->assertStringNotContainsString('PR terbuka', $payload['message']);
        $this->assertStringContainsString('di bawah ambangnya', $payload['message']);
    }

    /** …dan ketika memang semuanya tertutup PR, kalimat itulah yang benar. */
    public function test_a_warehouse_whose_shortages_are_all_covered_is_told_exactly_that(): void
    {
        $this->oneShortage();
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')->postJson('api/inventory/reorder/requisitions', [])->assertCreated();

        $payload = $this->actingAs($admin, 'sanctum')
            ->postJson('api/inventory/reorder/requisitions', [])
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('sudah ada di PR atau PO terbuka', $payload['message']);
    }

    /**
     * Gudang yang tidak ada adalah permintaan yang SALAH, bukan jawaban
     * "tidak ada kekurangan". Aturan `exists` sudah ditegakkan di
     * ReorderRuleStoreRequest; ia bocor di permukaan ini.
     */
    public function test_a_requisition_run_for_a_warehouse_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('api/inventory/reorder/requisitions', ['warehouse_id' => 99999])
            ->assertStatus(422);
    }

    /**
     * IDEMPOTENSI LINTAS STATUS — dan bukan hanya lengan Draf.
     *
     * Mempersempit OPEN_STATUSES menjadi [Draft] lolos seluruh suite hijau
     * sebelum uji ini ada. Akibatnya: barang yang sudah ada di PR DISETUJUI —
     * sedang berjalan menuju PO — muncul lagi sebagai "Akan diusulkan", dan
     * tombolnya menerbitkan permintaan KEDUA untuk kekurangan yang sama.
     */
    public static function openStatusProvider(): array
    {
        return [
            'diajukan' => [DocumentStatus::Submitted, 'Diajukan'],
            'disetujui' => [DocumentStatus::Approved, 'Disetujui'],
        ];
    }

    #[DataProvider('openStatusProvider')]
    public function test_a_requisition_that_is_already_moving_still_blocks_the_item(DocumentStatus $status, string $label): void
    {
        [$warehouse, $item] = $this->oneShortage();

        $pr = app(PurchaseRequisitionService::class)->create([
            'warehouse_id' => $warehouse->id,
            'items' => [['item_id' => $item->id, 'qty' => 7, 'unit' => 'roll']],
        ]);
        $pr->forceFill(['status' => $status])->save();

        $row = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk()
            ->json('data.rows.0');

        $this->assertTrue($row['skipped'], "PR berstatus {$label} harus tetap menahan usulan.");
        $this->assertStringContainsString($label, (string) $row['skipped_reason']);
        $this->assertSame($pr->code, $row['skipped_requisition_code']);
    }

    /**
     * PR YANG SUDAH DIBUANG TIDAK MENAHAN APA PUN — dan "belum dibuang" adalah
     * bagian dari aturan yang dinyatakan, bukan kebetulan implementasi.
     *
     * Tanpa penjaga ini: seseorang membuang PR draf yang salah — jalan
     * pemulihan yang paling wajar — lalu setiap baris berbunyi "Dilewati:
     * sudah diminta pada PR/…" untuk dokumen yang sudah tidak ada, dan
     * barang itu tidak pernah bisa diusulkan lagi.
     */
    public function test_a_discarded_requisition_releases_the_shortage_again(): void
    {
        $this->oneShortage();
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')->postJson('api/inventory/reorder/requisitions', [])->assertCreated();
        PurchaseRequisition::query()->firstOrFail()->delete();

        $payload = $this->actingAs($admin, 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk()
            ->json('data');

        $this->assertFalse($payload['rows'][0]['skipped']);
        $this->assertSame(1, $payload['rules']['proposable']);
        $this->assertSame(0, $payload['rules']['skipped']);
    }

    /**
     * JUMLAH PESAN ATURAN SAMPAI KE DOKUMENNYA, bukan hanya ke barisnya.
     *
     * Fixture oneShortage() memakai reorder_qty 0, jadi usulan == kekurangan
     * dan sebuah baris PR yang memakai shortage_qty lolos hijau. Di sini
     * keduanya sengaja BERBEDA angkanya: layar mencetak "usulan pesan 200",
     * dan PR yang tombol itu buat harus berisi 200 — bukan 7.
     */
    public function test_the_requisition_line_carries_the_rules_order_quantity_not_the_shortage(): void
    {
        $warehouse = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 0, 'unit' => 'zak']);
        $this->balance($warehouse->id, $item->id, 5);
        ReorderRule::create([
            'warehouse_id' => $warehouse->id, 'item_id' => $item->id,
            'reorder_point' => 12, 'reorder_qty' => 200, 'is_active' => true,
        ]);

        $admin = $this->adminUser();

        $row = $this->actingAs($admin, 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk()
            ->json('data.rows.0');

        $this->assertSame(7.0, (float) $row['shortage_qty']);
        $this->assertSame(200.0, (float) $row['suggested_qty']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('api/inventory/reorder/requisitions', [])
            ->assertCreated();

        $pr = PurchaseRequisition::query()->with('items')->firstOrFail();
        $this->assertSame('200.000', $pr->items[0]->qty, 'Angka yang diketik penjaga gudang harus sampai ke kertas PR.');
    }

    /**
     * PO TERBUKA YANG DIBUAT TANPA PR MENAHAN USULAN — dan berkata "dipesan",
     * bukan "diminta".
     *
     * PurchaseOrderStoreRequest MENGIZINKAN PO tanpa PR
     * (`purchase_requisition_id` nullable + `pr_bypass_reason` wajib bila
     * kosong), dan data demo memuat contohnya. Sebelum ini penjaganya hanya
     * membaca baris PR: barang yang sudah ada di PO Disetujui — uangnya sudah
     * terikat — muncul lagi sebagai "Akan diusulkan", dan kartu "Yang
     * dilewati" tidak menyebut PO sama sekali. Menekan tombolnya melahirkan
     * permintaan kedua, dan itu baru terlihat ketika barangnya datang dua
     * kali.
     */
    public function test_an_open_purchase_order_made_without_a_requisition_blocks_the_shortage(): void
    {
        [$warehouse, $item] = $this->oneShortage();
        $po = $this->purchaseOrder($warehouse->id, $item->id, DocumentStatus::Approved);

        $payload = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk()
            ->json('data');

        $row = $payload['rows'][0];
        $this->assertTrue($row['skipped']);
        $this->assertSame('po', $row['skipped_kind']);
        $this->assertSame($po, $row['skipped_requisition_code']);
        $this->assertStringContainsString("Sudah dipesan pada {$po}", (string) $row['skipped_reason']);
        $this->assertStringContainsString('PO terbuka', (string) $payload['why_skipped']);
    }

    /** …dan PO yang SELESAI tidak menahan: barangnya sudah masuk gudang. */
    public function test_a_closed_purchase_order_does_not_block_the_shortage_that_remains(): void
    {
        [$warehouse, $item] = $this->oneShortage();
        $this->purchaseOrder($warehouse->id, $item->id, DocumentStatus::Closed);

        $row = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk()
            ->json('data.rows.0');

        $this->assertFalse($row['skipped'], 'PO yang closed sudah diterima penuh; kekurangan yang tersisa nyata.');
    }

    /** …dan PO untuk GUDANG LAIN tidak menutup kekurangan gudang ini. */
    public function test_an_open_purchase_order_for_another_warehouse_does_not_cover_this_one(): void
    {
        [, $item] = $this->oneShortage();
        $central = $this->makeWarehouse('GD-PUSAT');
        $this->purchaseOrder($central->id, $item->id, DocumentStatus::Approved);

        $row = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk()
            ->json('data.rows.0');

        $this->assertFalse($row['skipped']);
    }

    /** Satu PO terbuka, dibuat tanpa PR — jalur yang PurchaseOrderStoreRequest izinkan. */
    private function purchaseOrder(int $warehouseId, int $itemId, DocumentStatus $status): string
    {
        $vendor = Vendor::query()->create([
            'code' => 'VND-9001', 'name' => 'PT Pemasok Uji',
            'is_pkp' => true, 'is_subcontractor' => false, 'classification' => 'material', 'status' => 'active',
        ]);

        $order = PurchaseOrder::query()->create([
            'code' => 'PO/2026/IX/9001',
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouseId,
            'purchase_requisition_id' => null,
            'pr_bypass_reason' => 'Pembelian mendesak di lapangan.',
            'order_date' => now()->toDateString(),
            'status' => $status,
        ]);

        $order->items()->create([
            'line_no' => 1, 'item_id' => $itemId, 'description' => 'Kabel UTP Cat6',
            'qty' => 50, 'unit' => 'roll', 'unit_price' => 1150000, 'amount' => 57500000,
        ]);

        return $order->code;
    }

    /**
     * KALIMAT ATURAN PELEWATAN datang dari server — CONVENTIONS §32 — dan
     * sampai uji ini ada, tidak satu pun uji PHP memakunya: hanya harness yang
     * menangkapnya, jadi ia hilang dari gerbang rilis paket berikutnya.
     *
     * Yang dipaku bukan seluruh kalimatnya (paku rapuh yang jatuh pada setiap
     * perbaikan tanda baca) melainkan kata-kata yang membuatnya BENAR.
     */
    public function test_the_skip_rule_sentence_names_the_three_open_statuses_and_the_three_that_do_not_block(): void
    {
        $this->oneShortage();

        $why = (string) $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/reorder/proposal')
            ->assertOk()
            ->json('data.why_skipped');

        foreach (['draf', 'diajukan', 'disetujui'] as $open) {
            $this->assertStringContainsString($open, mb_strtolower($why), "Aturan pelewatan harus menyebut status terbuka \"{$open}\".");
        }

        foreach (['ditolak', 'dibatalkan'] as $closed) {
            $this->assertStringContainsString($closed, mb_strtolower($why), "Aturan pelewatan harus menyebut bahwa \"{$closed}\" TIDAK menahan.");
        }
    }

    /**
     * TIDAK ADA JALAN dari layar ini ke "diajukan". Rute yang mengajukan
     * sebuah PR hanya satu, milik Procurement, dan menuntut prc.update —
     * yang dipaku di sini adalah bahwa modul Inventory tidak menumbuhkan
     * rute kedua ke sana.
     */
    public function test_the_inventory_module_exposes_no_route_that_submits_or_approves_a_requisition(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/inventory/reorder/'))
            ->map(fn ($route) => $route->uri())
            ->values();

        $this->assertContains('api/inventory/reorder/requisitions', $routes->all());
        $this->assertContains('api/inventory/reorder/proposal', $routes->all());

        // DUA rute reorder, tidak lebih. Sebuah rute ketiga di bawah awalan ini
        // adalah jalan yang belum pernah dibahas siapa pun.
        $this->assertCount(2, $routes, 'Awalan reorder menumbuhkan rute ketiga: '.$routes->implode(', '));

        foreach ($routes as $uri) {
            $this->assertStringNotContainsString('submit', $uri, "Rute {$uri} mengajukan sesuatu.");
            $this->assertStringNotContainsString('approve', $uri, "Rute {$uri} menyetujui sesuatu.");
        }
    }
}
