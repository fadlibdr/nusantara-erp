<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Modules\Inventory\Models\StockBalance;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * Satu kode yang dipindai → item yang dimaksudnya (F-6).
 *
 * ============================ PERANGKAP E ============================
 * `inv_items.barcode` NULLABLE DAN TIDAK UNIK. Dua item yang membawa barcode
 * yang sama bukan kemungkinan teoretis — dua kardus dari pemasok yang sama,
 * satu kolom yang tersalin saat menduplikasi kartu item, satu impor master
 * yang mengisi kolom yang keliru.
 *
 * Endpoint ini tidak boleh memilih yang pertama. Kalau ia memilih, stok masuk
 * ke kartu barang lain tanpa satu pun pesan, dan kekeliruan itu baru terlihat
 * pada opname berikutnya sebagai dua selisih yang tidak ada penjelasannya.
 * =====================================================================
 */
class ItemScanTest extends ErpTestCase
{
    use InventoryFixtures;

    private ?User $admin = null;

    /** Satu admin per uji: adminUser() membuat baris users baru tiap panggilan. */
    private function scan(string $code): array
    {
        $this->admin ??= $this->adminUser();

        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('api/inventory/items/scan?code='.urlencode($code))
            ->assertOk()
            ->json('data');
    }

    public function test_a_supplier_barcode_finds_its_item_with_the_stock_per_warehouse(): void
    {
        $warehouse = $this->makeWarehouse('GD-PUSAT');
        $item = $this->makeItem('Kabel UTP Cat6', ['code' => 'ITM-0003', 'barcode' => '8991002123458', 'unit' => 'roll']);
        StockBalance::query()->create(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'qty' => 7, 'avg_cost' => 1000]);

        $payload = $this->scan('8991002123458');

        $this->assertSame('one', $payload['status']);
        $this->assertCount(1, $payload['matches']);
        $this->assertSame('ITM-0003', $payload['matches'][0]['code']);
        $this->assertSame('barcode', $payload['matches'][0]['matched_on']);
        $this->assertSame('GD-PUSAT', $payload['matches'][0]['balances'][0]['warehouse_code']);
        $this->assertSame(7.0, (float) $payload['matches'][0]['balances'][0]['qty']);
    }

    /** Label F/LBL mencetak KODE ITEM bila kartunya tidak punya barcode, jadi kode item harus ikut ditemukan. */
    public function test_the_item_code_itself_is_scannable_because_that_is_what_the_label_prints(): void
    {
        $item = $this->makeItem('Semen Portland', ['code' => 'ITM-0001', 'unit' => 'zak']);

        $payload = $this->scan('ITM-0001');

        $this->assertSame('one', $payload['status']);
        $this->assertSame($item->id, $payload['matches'][0]['id']);
        $this->assertSame('code', $payload['matches'][0]['matched_on']);
    }

    /**
     * HURUF KECIL TETAP MENEMUKAN ITEMNYA — dan sebabnya papan ketik iPhone.
     *
     * Jalur ketik adalah SATU-SATUNYA jalur di iOS (tidak ada BarcodeDetector
     * di Safari), dan papan ketik iOS mengapitalkan huruf pertama secara
     * bawaan: orang gudang mengetik "itm-0003" dan yang terkirim "Itm-0003".
     * Sebelum perbaikan ini ia membaca "Tidak ada item dengan barcode atau
     * kode \"Itm-0003\"", membuka kartu itemnya, melihat kodenya memang ada di
     * sana, dan menyimpulkan pemindainya rusak.
     *
     * Permukaan saudaranya sudah menjawab begitu sejak lama — baris terakhir
     * uji ini memakunya, supaya keduanya tidak bisa menyimpang lagi.
     */
    #[DataProvider('caseVariants')]
    public function test_a_scan_finds_its_item_whatever_the_keyboard_did_to_the_case(string $typed, string $matchedOn): void
    {
        $this->makeItem('Kabel UTP Cat6', ['code' => 'ITM-0003', 'barcode' => 'IDN8991002x', 'unit' => 'roll']);

        $payload = $this->scan($typed);

        $this->assertSame('one', $payload['status'], "Kode \"{$typed}\" tidak menemukan itemnya.");
        $this->assertSame('ITM-0003', $payload['matches'][0]['code']);
        $this->assertSame($matchedOn, $payload['matches'][0]['matched_on'],
            'Sebab kecocokan harus tetap benar meski besar-kecilnya berbeda.');
    }

    public static function caseVariants(): array
    {
        return [
            'kode persis' => ['ITM-0003', 'code'],
            'kode huruf kecil' => ['itm-0003', 'code'],
            'kode diapitalkan papan ketik iOS' => ['Itm-0003', 'code'],
            'barcode persis' => ['IDN8991002x', 'barcode'],
            'barcode huruf besar semua' => ['IDN8991002X', 'barcode'],
            'barcode huruf kecil semua' => ['idn8991002x', 'barcode'],
        ];
    }

    /** …dan permukaan saudaranya menjawab sama — itulah pembanding yang dulu berselisih. */
    public function test_the_item_search_and_the_scan_answer_the_same_lowercase_code(): void
    {
        $this->makeItem('Kabel UTP Cat6', ['code' => 'ITM-0003', 'unit' => 'roll']);
        $this->admin ??= $this->adminUser();

        $searched = $this->actingAs($this->admin, 'sanctum')
            ->getJson('api/inventory/items?q=itm-0003')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $searched, 'Pencarian item memang tidak peka huruf; pindai harus sepakat.');
        $this->assertSame('one', $this->scan('itm-0003')['status']);
    }

    /**
     * PERANGKAP E. Dua item, satu barcode: KEDUANYA dipulangkan, statusnya
     * ambiguous, dan kalimatnya menyebut berapa banyak.
     */
    public function test_a_barcode_on_two_items_returns_both_and_says_so(): void
    {
        $first = $this->makeItem('Kabel UTP Cat6 (kardus lama)', ['code' => 'ITM-0003', 'barcode' => '8991002123458']);
        $second = $this->makeItem('Kabel UTP Cat6 (kardus baru)', ['code' => 'ITM-0044', 'barcode' => '8991002123458']);

        $payload = $this->scan('8991002123458');

        $this->assertSame('ambiguous', $payload['status']);
        $this->assertCount(2, $payload['matches']);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            array_column($payload['matches'], 'id'),
            'Kedua item harus ikut — memilihkan salah satunya memasukkan stok ke kartu barang lain.',
        );
        $this->assertStringContainsString('2 ITEM memakai kode yang sama', $payload['message']);
    }

    /**
     * …termasuk ketika satu kode adalah BARCODE sebuah item DAN KODE item
     * lain sekaligus. Tiap baris menyebut sebabnya sendiri, karena orang yang
     * memilih di antara keduanya berhak tahu.
     */
    public function test_a_code_that_is_one_items_barcode_and_another_items_code_reports_both_reasons(): void
    {
        $this->makeItem('Item berkode', ['code' => 'ITM-0007']);
        $this->makeItem('Item berbarcode', ['code' => 'ITM-0088', 'barcode' => 'ITM-0007']);

        $payload = $this->scan('ITM-0007');

        $this->assertSame('ambiguous', $payload['status']);
        $this->assertEqualsCanonicalizing(
            ['code', 'barcode'],
            array_column($payload['matches'], 'matched_on'),
        );
    }

    public function test_an_unknown_code_says_nothing_matched_and_where_to_look(): void
    {
        $this->makeItem('Semen Portland', ['code' => 'ITM-0001']);

        $payload = $this->scan('TIDAK-ADA-INI');

        $this->assertSame('none', $payload['status']);
        $this->assertSame([], $payload['matches']);
        $this->assertStringContainsString('TIDAK-ADA-INI', $payload['message']);
        $this->assertStringContainsString('kolom Barcode', $payload['message']);
    }

    /**
     * COCOK PERSIS, bukan "like". Pemindaian adalah pembacaan mesin: ia tepat
     * atau ia gagal. Pencocokan sebagian akan membuat ITM-000 menemukan
     * ITM-0001 dan orang gudang tidak punya cara mengetahuinya.
     */
    public function test_a_partial_code_matches_nothing(): void
    {
        $this->makeItem('Semen Portland', ['code' => 'ITM-0001']);

        $this->assertSame('none', $this->scan('ITM-000')['status'], 'Awalan kode tidak boleh cocok.');
        $this->assertSame('none', $this->scan('ITM-0001-X')['status'], 'Kode dengan ekor tambahan tidak boleh cocok.');
        $this->assertSame('one', $this->scan('ITM-0001')['status'], '…dan kode yang persis tetap cocok.');
    }

    /** Item yang dibuang tidak muncul: barangnya sudah tidak ada di daftar mana pun. */
    public function test_a_soft_deleted_item_is_not_scannable(): void
    {
        $item = $this->makeItem('Item Lama', ['code' => 'ITM-0099', 'barcode' => '1234567890128']);
        $item->delete();

        $this->assertSame('none', $this->scan('1234567890128')['status']);
    }

    /**
     * Item NONAKTIF tetap muncul, DITANDAI. Barangnya masih di rak; yang
     * dihentikan adalah pembeliannya, bukan keberadaannya — dan orang yang
     * memindai kardus di gudang justru perlu tahu kenapa ia tidak menemukannya
     * di daftar pembelian.
     */
    public function test_an_inactive_item_is_still_found_and_flagged(): void
    {
        $this->makeItem('Item Disetop', ['code' => 'ITM-0055', 'barcode' => '1234567890111', 'is_active' => false]);

        $payload = $this->scan('1234567890111');

        $this->assertSame('one', $payload['status']);
        $this->assertFalse($payload['matches'][0]['is_active']);
    }

    public function test_the_route_is_reachable_and_not_swallowed_by_the_item_show_route(): void
    {
        // items/scan berdiri DI ATAS items/{item}. Di bawahnya, 'scan'
        // tertangkap sebagai {item}, pengikatan modelnya gagal, dan setiap
        // pemindaian menjawab 404 — yang di lapangan terbaca sebagai
        // "pemindainya rusak".
        $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/items/scan?code=APAPUN')
            ->assertOk();
    }

    public function test_a_scan_without_a_code_is_refused(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/inventory/items/scan')
            ->assertStatus(422);
    }
}
