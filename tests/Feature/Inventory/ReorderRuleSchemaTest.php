<?php

namespace Tests\Feature\Inventory;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\MigrationDeclaredColumns;
use Modules\Inventory\Models\ReorderRule;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * Bentuk `inv_reorder_rules` (F-6) — dan satu-satunya jaring yang menahan
 * angka ubin launcher dari menggandakan dirinya.
 *
 * Kueri "di bawah minimum" menggabungkan tabel ini dengan LEFT JOIN atas
 * (warehouse_id, item_id). Dua baris untuk satu pasangan menggandakan setiap
 * baris kekurangan pasangan itu — di layar Saldo Stok, di widget, DAN di
 * registri ModuleCounts yang menghitung baris yang sama. Tidak ada satu pun
 * galat yang muncul; yang muncul adalah angka yang dua kali lebih besar.
 * Layanan menolaknya lebih dulu dengan kalimat Indonesia, UNIQUE di basis data
 * adalah jaring terakhirnya, dan uji ini yang menjaga jaring itu ada.
 */
class ReorderRuleSchemaTest extends ErpTestCase
{
    use InventoryFixtures;

    public function test_the_table_carries_the_columns_the_rule_needs(): void
    {
        $this->assertTrue(Schema::hasTable('inv_reorder_rules'));

        foreach (['id', 'warehouse_id', 'item_id', 'reorder_point', 'reorder_qty', 'is_active', 'notes', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('inv_reorder_rules', $column),
                "inv_reorder_rules kehilangan kolom {$column}.",
            );
        }

        // TANPA deleted_at, dan itu bukan kelalaian: lihat migrasi 001700.
        // Sebuah softDeletes() yang ditambahkan belakangan akan membuat UNIQUE
        // di bawah ini menahan pasangan yang barisnya sudah dibuang, sehingga
        // aturan yang dihapus tidak pernah bisa dibuat ulang.
        $this->assertFalse(
            Schema::hasColumn('inv_reorder_rules', 'deleted_at'),
            'inv_reorder_rules mendapat deleted_at. UNIQUE(warehouse_id, item_id) lalu menahan pasangan yang '
            .'sudah dibuang selamanya — aturan yang dihapus tidak bisa dibuat ulang. Saklarnya is_active.',
        );
    }

    public function test_a_second_rule_for_the_same_warehouse_and_item_is_refused_by_the_database(): void
    {
        $warehouse = $this->makeWarehouse('GD-RO1');
        $item = $this->makeItem('Semen Portland', ['min_stock' => 10]);

        ReorderRule::create([
            'warehouse_id' => $warehouse->id,
            'item_id' => $item->id,
            'reorder_point' => 25,
            'reorder_qty' => 100,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        ReorderRule::create([
            'warehouse_id' => $warehouse->id,
            'item_id' => $item->id,
            'reorder_point' => 40,
            'reorder_qty' => 200,
            'is_active' => true,
        ]);
    }

    /**
     * Pasangan yang BERBEDA tetap boleh — tanpa lengan ini uji di atas juga
     * lulus untuk sebuah UNIQUE yang keliru dipasang pada warehouse_id saja,
     * yang akan membuat satu gudang hanya boleh punya satu aturan seumur
     * hidupnya.
     */
    public function test_the_same_item_may_carry_one_rule_per_warehouse(): void
    {
        $central = $this->makeWarehouse('GD-PUSAT');
        $site = $this->makeWarehouse('GD-SITE');
        $item = $this->makeItem('Besi Beton D16', ['min_stock' => 10]);

        ReorderRule::create(['warehouse_id' => $central->id, 'item_id' => $item->id, 'reorder_point' => 200, 'reorder_qty' => 500, 'is_active' => true]);
        ReorderRule::create(['warehouse_id' => $site->id, 'item_id' => $item->id, 'reorder_point' => 20, 'reorder_qty' => 50, 'is_active' => true]);

        $this->assertSame(2, ReorderRule::query()->where('item_id', $item->id)->count());
    }

    /**
     * Skala kuantitas, dinyatakan (CONVENTIONS §4) — dan ini bukan formalitas.
     * Ambang yang dibandingkan dengan `inv_stock_balances.qty` harus punya
     * skala yang sama dengan saldonya; sebuah decimal(15,2) di sini membuat
     * 12,505 zak dibulatkan menjadi 12,51 di MySQL dan tetap 12,505 di SQLite,
     * dan perbandingan "< ambang" mulai menjawab berbeda per dialek.
     */
    public function test_the_quantity_columns_are_declared_at_the_house_scale(): void
    {
        $declared = MigrationDeclaredColumns::scan()['decimal']['inv_reorder_rules'] ?? [];

        foreach (['reorder_point', 'reorder_qty'] as $column) {
            $this->assertSame(
                ['precision' => 15, 'scale' => 3],
                $declared[$column] ?? null,
                "inv_reorder_rules.{$column} harus decimal(15,3), skala yang sama dengan inv_stock_balances.qty.",
            );
        }
    }

    /**
     * KEPING PERINGATANNYA ADA DI LAYAR, bukan hanya di jawaban server.
     *
     * `deleted_labels` yang dikirim resource tidak menandai apa pun kalau
     * tidak ada kolom yang menggambarnya: barisnya tetap terbaca "titik 80 ·
     * Aktif ✓" untuk aturan yang ambangnya sudah tidak menentukan apa pun.
     */
    public function test_the_rule_list_draws_the_warning_chip_the_resource_sends(): void
    {
        $source = (string) file_get_contents(public_path('app/js/schema.js'));
        $start = strpos($source, "'inventory/reorder-rules': {");
        $this->assertNotFalse($start, 'schema.js tidak lagi punya resource inventory/reorder-rules.');

        $block = substr($source, $start, 2500);

        $this->assertStringContainsString(
            "key: 'deleted_labels'",
            $block,
            'Daftar Aturan Reorder tidak menggambar keping "Item dibuang"/"Gudang dibuang" yang dikirim '
            .'ReorderRuleResource, jadi aturan yang ambangnya sudah tidak berlaku tampak hidup dengan Aktif ✓.',
        );
    }

    /**
     * Blok lanjutan Inventory DIDAFTARKAN di tabel CONVENTIONS §2, pada commit
     * yang sama dengan pemakaian pertamanya — aturan §2 sendiri. Sebuah
     * migrasi 001700 tanpa baris tabelnya berarti modul berikutnya yang
     * kehabisan blok akan mengambil rentang yang sama.
     */
    public function test_the_inventory_continuation_block_is_registered_in_the_conventions_table(): void
    {
        $conventions = (string) file_get_contents(base_path('docs/CONVENTIONS.md'));

        $this->assertMatchesRegularExpression(
            '/\|\s*Inventory\s*\|\s*000400–000499\s*\|\s*\*\*001700–001799\*\*\s*\|/u',
            $conventions,
            'Tabel "Blok lanjutan" CONVENTIONS §2 tidak memuat baris Inventory 001700–001799, sementara '
            .'migrasi 001700 sudah dipakai. §2 adalah sumber kebenaran rentang blok; tanpa barisnya modul '
            .'berikutnya yang kehabisan blok akan mengambil rentang yang sama.',
        );

        $this->assertNotEmpty(
            glob(base_path('Modules/Inventory/Database/Migrations/*_0017[0-9][0-9]_*.php')),
            'Tidak ada satu pun migrasi Inventory di blok 001700–001799, sementara §2 mendaftarkannya '
            .'sebagai DIPAKAI — didaftarkan pada commit pemakaian pertama, tidak lebih dulu.',
        );
    }
}
