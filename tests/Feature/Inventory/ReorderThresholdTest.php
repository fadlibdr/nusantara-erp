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

        $rows = collect($this->alerts())->keyBy(fn (object $row): int => (int) $row->warehouse_id);

        // Gudang pusat memakai angka ITEM: aturan gudang site tidak menurunkan
        // ambangnya, dan itulah yang diuji berkas ini sejak F-6.
        $this->assertSame('item', $rows[$central->id]->threshold_source,
            'Aturan gudang site tidak boleh menurunkan ambang gudang pusat.');
        $this->assertSame(100.0, (float) $rows[$central->id]->reorder_point);

        // …dan gudang SITE punya barisnya sendiri sejak putaran ketiga F-6: ia
        // menyatakan menyimpan barang ini (itulah arti barisnya) dan belum
        // punya satu pun saldo, jadi stoknya nol di bawah titik 20.
        $this->assertSame('rule', $rows[$site->id]->threshold_source);
        $this->assertSame(20.0, (float) $rows[$site->id]->reorder_point);
        $this->assertSame(0.0, (float) $rows[$site->id]->qty);

        $this->assertCount(2, $rows);
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
     * PASANGAN YANG BELUM PUNYA BARIS SALDO ADALAH KEADAAN YANG PALING
     * MEMBUTUHKAN PESAN ULANG — dan sampai putaran ketiga F-6 ia tidak pernah
     * menyala.
     *
     * `lowStockAlerts()` berangkat FROM `inv_stock_balances` dan menyapa tabel
     * aturan lewat LEFT JOIN, jadi sepasang gudang × item yang belum pernah
     * kemasukan barang tidak punya baris untuk berangkat. Seseorang menyatakan
     * "gudang ini menyimpan barang ini, titik pesan ulang 100" untuk barang
     * yang stoknya NOL karena belum pernah masuk — dan tiga layar berkata
     * aturannya berlaku sementara daftar kekurangan, usulan PR dan tab "Perlu
     * dipesan ulang" semuanya kosong.
     *
     * Aturan itu SENDIRI yang menyatakan bahwa gudang ini menyimpan barang
     * ini; itulah bedanya dengan "setiap item × setiap gudang", yang tidak
     * pernah boleh dihitung.
     */
    public function test_a_rule_for_a_pair_that_has_never_held_stock_still_reaches_the_list(): void
    {
        $warehouse = $this->makeWarehouse('GD-BARU');
        $item = $this->makeItem('Kabel UTP Cat6', ['min_stock' => 0]);

        // Tidak ada satu baris pun di inv_stock_balances untuk pasangan ini.
        ReorderRule::create([
            'warehouse_id' => $warehouse->id, 'item_id' => $item->id,
            'reorder_point' => 100, 'reorder_qty' => 250, 'is_active' => true,
        ]);

        $rows = $this->alerts();
        $this->assertCount(1, $rows, 'Aturan hidup tanpa baris saldo tidak pernah bisa menyala.');

        $row = $rows[0];
        $this->assertSame($warehouse->id, (int) $row->warehouse_id);
        $this->assertSame($item->id, (int) $row->item_id);
        $this->assertSame(0.0, (float) $row->qty, 'Pasangan tanpa baris saldo berarti stok nol, bukan stok tak diketahui.');
        $this->assertSame(100.0, (float) $row->reorder_point);
        $this->assertSame('rule', $row->threshold_source);
        $this->assertSame('Aturan reorder gudang ini', $row->threshold_source_label);
        $this->assertSame(0.0, (float) $row->min_stock, 'Angka item yang kalah tetap ikut di baris.');
        $this->assertSame(100.0, (float) $row->shortage_qty);
        $this->assertSame(250.0, (float) $row->suggested_qty);
        $this->assertSame($item->unit, $row->unit);
        $this->assertSame($warehouse->code, $row->warehouse_code);
        $this->assertSame($item->code, $row->item_code);

        // …dan saringan gudang tetap menyaring lengan ini juga.
        $this->assertCount(1, $this->alerts($warehouse->id));
        $this->assertCount(0, $this->alerts($this->makeWarehouse('GD-LAIN')->id));
    }

    /**
     * …DAN LENGAN ITU MEMATUHI SYARAT YANG SAMA. Sebuah aturan yang tidak
     * BERLAKU tidak menerbitkan baris hanya karena pasangannya belum punya
     * saldo — kalau tidak, lengan kedua menjadi pintu belakang yang melewati
     * keempat syarat `governing()`.
     */
    public function test_a_pair_without_a_balance_row_obeys_the_same_conditions(): void
    {
        $warehouse = $this->makeWarehouse('GD-BARU');
        $doomed = $this->makeWarehouse('GD-DIBUANG');

        $off = $this->makeItem('Aturan nonaktif', ['min_stock' => 0]);
        $zero = $this->makeItem('Titik nol', ['min_stock' => 0]);
        $stopped = $this->makeItem('Item nonaktif', ['min_stock' => 0, 'is_active' => false]);
        $gone = $this->makeItem('Item dibuang', ['min_stock' => 0]);
        $elsewhere = $this->makeItem('Gudang dibuang', ['min_stock' => 0]);

        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $off->id, 'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => false]);
        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $zero->id, 'reorder_point' => 0, 'reorder_qty' => 0, 'is_active' => true]);
        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $stopped->id, 'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => true]);
        ReorderRule::create(['warehouse_id' => $warehouse->id, 'item_id' => $gone->id, 'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => true]);
        ReorderRule::create(['warehouse_id' => $doomed->id, 'item_id' => $elsewhere->id, 'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => true]);

        $gone->delete();
        $doomed->delete();

        $this->assertCount(0, $this->alerts(),
            'Lengan "belum punya saldo" melewati syarat yang ditegakkan lengan pertama.');
    }

    /**
     * …DAN IA TIDAK MENGGANDAKAN BARIS yang sudah dibawa lengan pertama.
     * Sebuah pasangan yang PUNYA baris saldo dihitung sekali, dan sebuah baris
     * saldo bersaldo cukup tetap diam meski aturannya ada.
     */
    public function test_a_pair_that_does_have_a_balance_row_is_still_counted_once(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $kurang = $this->makeItem('Semen Portland', ['min_stock' => 0]);
        $cukup = $this->makeItem('Besi Beton D16', ['min_stock' => 0]);

        $this->balance($warehouse->id, $kurang->id, 30);
        $this->balance($warehouse->id, $cukup->id, 300);

        foreach ([$kurang, $cukup] as $item) {
            ReorderRule::create([
                'warehouse_id' => $warehouse->id, 'item_id' => $item->id,
                'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => true,
            ]);
        }

        $rows = $this->alerts();

        $this->assertCount(1, $rows);
        $this->assertSame($kurang->id, (int) $rows[0]->item_id);
        $this->assertSame(30.0, (float) $rows[0]->qty, 'Saldo yang benar-benar ada tidak boleh tertimpa nol.');
    }

    /**
     * "ATURAN MANA YANG BENAR-BENAR BERLAKU" PUNYA SATU DEFINISI, DAN INILAH
     * YANG MEMAKUNYA (putaran kedua F-6).
     *
     * Syaratnya — aturannya aktif, itemnya hidup, gudangnya hidup, ITEMNYA
     * AKTIF — dulu ditegakkan di tiga tempat dengan tiga isi yang berbeda:
     * kueri kekurangan memeriksa keempatnya, hitungan kartu item hanya
     * `is_active` aturannya, dan `applies` pada daftar aturan hanya kedua
     * `deleted_at`-nya. Akibatnya kartu item berkata "stok minimum di atas
     * TIDAK berlaku" untuk aturan yang gudangnya sudah dibuang, sementara
     * layar sebelahnya menandai baris yang sama "Gudang dibuang".
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
        // Item yang BERHENTI DIBELI — dinonaktifkan, tidak dibuang. Itu jalur
        // normalnya, dan kueri kekurangan sudah lama membuangnya lewat
        // `i.is_active`; syarat keempat itulah yang dulu tidak ikut ke
        // `governing()`.
        $stop = $this->makeItem('Keramik Diskontinu', ['min_stock' => 0, 'is_active' => false]);

        foreach ([$semen, $besi, $kabel, $stop] as $item) {
            $this->balance($live->id, $item->id, 0);
            $this->balance($doomed->id, $item->id, 0);
        }

        $governing = ReorderRule::create(['warehouse_id' => $live->id, 'item_id' => $semen->id, 'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => true]);
        ReorderRule::create(['warehouse_id' => $live->id, 'item_id' => $besi->id, 'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => false]);
        $itemGone = ReorderRule::create(['warehouse_id' => $live->id, 'item_id' => $kabel->id, 'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => true]);
        $warehouseGone = ReorderRule::create(['warehouse_id' => $doomed->id, 'item_id' => $semen->id, 'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => true]);
        $itemOff = ReorderRule::create(['warehouse_id' => $live->id, 'item_id' => $stop->id, 'reorder_point' => 100, 'reorder_qty' => 0, 'is_active' => true]);

        $kabel->delete();
        $doomed->delete();

        $this->assertSame(
            [$governing->id],
            ReorderRule::query()->governing()->orderBy('id')->pluck('id')->all(),
            'Scope `governing` tidak menyaring keempat syaratnya.',
        );

        $obeyed = array_values(array_unique(array_filter(array_map(
            fn (object $row): ?int => $row->reorder_rule_id === null ? null : (int) $row->reorder_rule_id,
            $this->alerts(),
        ))));

        $this->assertSame([$governing->id], $obeyed,
            'Kueri kekurangan mematuhi kumpulan aturan yang berbeda dari `governing`.');

        // …dan bentuk BARISNYA sama dengan bentuk SCOPE-nya, satu per satu.
        foreach ([$governing, $itemGone, $warehouseGone, $itemOff] as $rule) {
            $this->assertSame(
                in_array($rule->id, $obeyed, true),
                $rule->fresh()->governs(),
                "Predikat baris dan kueri tidak sepakat tentang aturan #{$rule->id}.",
            );
        }
    }
}
