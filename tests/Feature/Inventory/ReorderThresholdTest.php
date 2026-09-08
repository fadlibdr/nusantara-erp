<?php

namespace Tests\Feature\Inventory;

use Modules\Inventory\Models\ReorderRule;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Services\StockService;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * ARTI "PERLU DIPESAN ULANG" (F-6) — satu definisi, diuji di sumbernya.
 *
 * Sampai F-6 ambangnya adalah `inv_items.min_stock`: satu angka untuk seluruh
 * perusahaan. Sejak F-6 sebuah aturan AKTIF untuk pasangan (gudang, item)
 * MENGGANTIKAN angka itu untuk pasangan tersebut — bukan menambahnya, dan
 * bukan "yang paling ketat menang". Lengan terakhir itulah yang paling mudah
 * ditulis keliru dan paling sulit dilihat: `max(min_stock, reorder_point)`
 * memberikan jawaban yang sama untuk setiap aturan yang lebih TINGGI, dan
 * baru salah pada aturan yang lebih rendah — yaitu justru aturan gudang site,
 * yang merupakan alasan tabelnya dibuat.
 *
 * Kesetaraan dengan salinan registri Core diuji di tempat lain
 * (Tests\Feature\Core\ModuleCountsTest), dengan fixture yang jawabannya
 * berbeda antara "dengan aturan" dan "hanya min_stock".
 */
class ReorderThresholdTest extends ErpTestCase
{
    use InventoryFixtures;

    private function balance(int $warehouseId, int $itemId, float $qty): void
    {
        StockBalance::query()->create([
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'qty' => $qty,
            'avg_cost' => 1000,
        ]);
    }

    private function alerts(?int $warehouseId = null): array
    {
        return app(StockService::class)->lowStockAlerts($warehouseId)->all();
    }

    public function test_an_active_rule_replaces_the_items_minimum_even_when_it_is_lower(): void
    {
        $warehouse = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Kabel UTP Cat6', ['min_stock' => 100]);
        $this->balance($warehouse->id, $item->id, 50);

        // Tanpa aturan: 50 < 100, jadi ia muncul.
        $this->assertCount(1, $this->alerts());

        ReorderRule::create([
            'warehouse_id' => $warehouse->id, 'item_id' => $item->id,
            'reorder_point' => 20, 'reorder_qty' => 0, 'is_active' => true,
        ]);

        // Dengan aturan gudang yang LEBIH RENDAH: 50 >= 20, jadi ia diam.
        // `max(min_stock, reorder_point)` akan tetap menjawab 100 di sini.
        $this->assertSame([], $this->alerts());
    }

    public function test_an_active_rule_can_also_raise_a_pair_above_an_item_that_declares_no_minimum(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $item = $this->makeItem('Ready Mix K-300', ['min_stock' => 0]);
        $this->balance($warehouse->id, $item->id, 5);

        $this->assertSame([], $this->alerts(), 'min_stock 0 berarti item tanpa ambang.');

        ReorderRule::create([
            'warehouse_id' => $warehouse->id, 'item_id' => $item->id,
            'reorder_point' => 12, 'reorder_qty' => 0, 'is_active' => true,
        ]);

        $rows = $this->alerts();
        $this->assertCount(1, $rows);
        $this->assertSame(12.0, (float) $rows[0]->reorder_point);
        $this->assertSame(7.0, (float) $rows[0]->shortage_qty);
        $this->assertSame('rule', $rows[0]->threshold_source);
        // Angka item yang KALAH tetap ikut, supaya layar bisa mencetak keduanya.
        $this->assertSame(0.0, (float) $rows[0]->min_stock);
    }

    public function test_a_rule_that_is_switched_off_decides_nothing(): void
    {
        $warehouse = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Besi Beton D16', ['min_stock' => 100]);
        $this->balance($warehouse->id, $item->id, 50);

        ReorderRule::create([
            'warehouse_id' => $warehouse->id, 'item_id' => $item->id,
            'reorder_point' => 20, 'reorder_qty' => 0, 'is_active' => false,
        ]);

        $rows = $this->alerts();
        $this->assertCount(1, $rows, 'Aturan nonaktif tidak boleh menurunkan ambang.');
        $this->assertSame('item', $rows[0]->threshold_source);
        $this->assertSame(100.0, (float) $rows[0]->reorder_point);
    }

    /**
     * `is_active` HARUS berada di klausa ON join, bukan di WHERE. Di WHERE ia
     * mengubah LEFT JOIN menjadi INNER JOIN, dan setiap pasangan yang TIDAK
     * punya aturan sama sekali hilang dari daftar sekaligus — kegagalan yang
     * tidak terlihat sampai ada satu aturan nonaktif di basis data.
     */
    public function test_pairs_without_any_rule_survive_alongside_a_switched_off_rule(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $ruled = $this->makeItem('Semen Portland', ['min_stock' => 100]);
        $plain = $this->makeItem('Pasir Beton', ['min_stock' => 30]);
        $this->balance($warehouse->id, $ruled->id, 50);
        $this->balance($warehouse->id, $plain->id, 10);

        ReorderRule::create([
            'warehouse_id' => $warehouse->id, 'item_id' => $ruled->id,
            'reorder_point' => 20, 'reorder_qty' => 0, 'is_active' => false,
        ]);

        $this->assertCount(2, $this->alerts());
    }

    public function test_an_active_rule_at_zero_means_this_pair_is_never_reordered(): void
    {
        $warehouse = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('CCTV Dome 4MP', ['min_stock' => 5]);
        $this->balance($warehouse->id, $item->id, 3);

        ReorderRule::create([
            'warehouse_id' => $warehouse->id, 'item_id' => $item->id,
            'reorder_point' => 0, 'reorder_qty' => 0, 'is_active' => true,
        ]);

        $this->assertSame(
            [],
            $this->alerts(),
            'Syarat "> 0" berlaku pada ambang yang MENANG, bukan pada min_stock: aturan aktif bertitik 0 '
            .'berarti pasangan ini memang tidak pernah dipesan ulang.',
        );
    }

    public function test_a_rule_belongs_to_the_pair_and_never_leaks_across_warehouses(): void
    {
        $central = $this->makeWarehouse('GD-PUSAT');
        $site = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Switch Managed 24 Port', ['min_stock' => 100]);
        $this->balance($central->id, $item->id, 50);

        ReorderRule::create([
            'warehouse_id' => $site->id, 'item_id' => $item->id,
            'reorder_point' => 20, 'reorder_qty' => 0, 'is_active' => true,
        ]);

        $rows = $this->alerts();
        $this->assertCount(1, $rows, 'Aturan gudang site tidak boleh menurunkan ambang gudang pusat.');
        $this->assertSame('item', $rows[0]->threshold_source);
        $this->assertSame(100.0, (float) $rows[0]->reorder_point);
    }

    public function test_the_suggested_quantity_is_the_rules_order_quantity_when_it_names_one(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $withQty = $this->makeItem('Semen Portland', ['min_stock' => 0]);
        $withoutQty = $this->makeItem('Pasir Beton', ['min_stock' => 0]);
        $this->balance($warehouse->id, $withQty->id, 5);
        $this->balance($warehouse->id, $withoutQty->id, 5);

        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $withQty->id, 'reorder_point' => 12, 'reorder_qty' => 200, 'is_active' => true]);
        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $withoutQty->id, 'reorder_point' => 12, 'reorder_qty' => 0, 'is_active' => true]);

        $rows = collect($this->alerts())->keyBy('item_id');

        $this->assertSame(200.0, (float) $rows[$withQty->id]->suggested_qty);
        $this->assertSame(200.0, (float) $rows[$withQty->id]->reorder_qty);

        // 0 di kolom berarti "tidak dinyatakan", bukan "pesan nol".
        $this->assertNull($rows[$withoutQty->id]->reorder_qty);
        $this->assertSame(7.0, (float) $rows[$withoutQty->id]->suggested_qty);
    }

    /** Layar Saldo Stok membaca endpoint ini; medan barunya harus ikut menyeberang. */
    public function test_the_low_stock_endpoint_carries_the_winning_threshold_and_its_source(): void
    {
        $warehouse = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Kabel UTP Cat6', ['min_stock' => 100]);
        $this->balance($warehouse->id, $item->id, 50);
        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'reorder_point' => 80, 'reorder_qty' => 25, 'is_active' => true]);

        $row = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/stock/low-stock')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('rule', $row['threshold_source']);
        $this->assertSame('Aturan reorder gudang ini', $row['threshold_source_label']);
        $this->assertSame(80.0, (float) $row['reorder_point']);
        $this->assertSame(100.0, (float) $row['min_stock']);
        $this->assertSame(30.0, (float) $row['shortage_qty']);
        $this->assertSame(25.0, (float) $row['suggested_qty']);
    }

    public function test_the_warehouse_filter_still_narrows_the_list(): void
    {
        $central = $this->makeWarehouse('GD-PUSAT');
        $site = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 100]);
        $this->balance($central->id, $item->id, 50);
        $this->balance($site->id, $item->id, 50);

        $this->assertCount(2, $this->alerts());
        $this->assertCount(1, $this->alerts($site->id));
    }

    /**
     * "ATURAN MANA YANG BENAR-BENAR BERLAKU" PUNYA SATU DEFINISI, DAN INILAH
     * YANG MEMAKUNYA (putaran kedua F-6).
     *
     * Tiga syaratnya — aktif, itemnya hidup, gudangnya hidup — dulu ditegakkan
     * di tiga tempat dengan tiga isi yang berbeda: kueri kekurangan memeriksa
     * ketiganya, hitungan kartu item hanya `is_active`, dan `applies` pada
     * daftar aturan hanya kedua `deleted_at`-nya. Akibatnya kartu item berkata
     * "stok minimum di atas TIDAK berlaku" untuk aturan yang gudangnya sudah
     * dibuang, sementara layar sebelahnya menandai baris yang sama "Gudang
     * dibuang".
     *
     * Sekarang keduanya memanggil `ReorderRule::governing()` /
     * `->governs()`, dan uji ini menuntut KESETARAANNYA dengan kueri yang
     * benar-benar menghitung kekurangan — bukan tiga jawaban terpisah.
     */
    public function test_the_rules_that_govern_are_exactly_the_ones_the_shortage_query_obeys(): void
    {
        $live = $this->makeWarehouse('GD-HIDUP');
        $doomed = $this->makeWarehouse('GD-DIBUANG');

        // min_stock 0 di mana-mana: tanpa aturan yang MENANG tidak ada satu pun
        // baris kekurangan, jadi setiap baris yang muncul datang dari aturan.
        $semen = $this->makeItem('Semen Portland', ['min_stock' => 0]);
        $besi = $this->makeItem('Besi Beton D16', ['min_stock' => 0]);
        $kabel = $this->makeItem('Kabel UTP Cat6', ['min_stock' => 0]);

        foreach ([$semen, $besi, $kabel] as $item) {
            $this->balance($live->id, $item->id, 0);
            $this->balance($doomed->id, $item->id, 0);
        }

        $governing = ReorderRule::create(['warehouse_id' => $live->id, 'item_id' => $semen->id, 'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => true]);
        ReorderRule::create(['warehouse_id' => $live->id, 'item_id' => $besi->id, 'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => false]);
        $itemGone = ReorderRule::create(['warehouse_id' => $live->id, 'item_id' => $kabel->id, 'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => true]);
        $warehouseGone = ReorderRule::create(['warehouse_id' => $doomed->id, 'item_id' => $semen->id, 'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => true]);

        $kabel->delete();
        $doomed->delete();

        $this->assertSame(
            [$governing->id],
            ReorderRule::query()->governing()->orderBy('id')->pluck('id')->all(),
            'Scope `governing` tidak menyaring ketiga syaratnya.',
        );

        $obeyed = array_values(array_unique(array_filter(array_map(
            fn (object $row): ?int => $row->reorder_rule_id === null ? null : (int) $row->reorder_rule_id,
            $this->alerts(),
        ))));

        $this->assertSame([$governing->id], $obeyed,
            'Kueri kekurangan mematuhi kumpulan aturan yang berbeda dari `governing`.');

        // …dan bentuk BARISNYA sama dengan bentuk SCOPE-nya, satu per satu.
        foreach ([$governing, $itemGone, $warehouseGone] as $rule) {
            $this->assertSame(
                in_array($rule->id, $obeyed, true),
                $rule->fresh()->governs(),
                "Predikat baris dan kueri tidak sepakat tentang aturan #{$rule->id}.",
            );
        }
    }
}
