<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Inventory\Models\ReorderRule;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Services\PurchaseRequisitionService;
use Modules\Projects\Models\Project;
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
        $this->assertStringContainsString('sudah ada di PR terbuka', $second['message']);
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
