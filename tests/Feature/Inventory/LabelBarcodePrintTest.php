<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * Lembar label barcode F/LBL (F-6) — dari ujung ke ujung.
 *
 * Code128Test membuktikan batangnya benar dengan membacanya kembali. Yang
 * dibuktikan DI SINI adalah tiga hal yang hanya bisa salah di lembarnya:
 *
 *  - YANG MANA yang dikodekan. Barcode pemasok bila kartu item punya, kode
 *    item bila tidak — dan lembarnya menuliskan yang mana, karena mencetak
 *    ITM-0001 di samping barcode pabrik berarti dua kode untuk satu barang;
 *  - kode yang TIDAK BISA dikodekan tidak menghasilkan gambar yang salah.
 *    Barcode salah terbaca sebagai kode LAIN oleh pemindai, sehingga barang
 *    yang dipindai masuk ke kartu stok barang lain;
 *  - jumlah stiker mematuhi ?jumlah= dan menolak angka di luar rentang alih-
 *    alih menjepitnya diam-diam.
 */
class LabelBarcodePrintTest extends ErpTestCase
{
    use InventoryFixtures;

    private function url(int $itemId, string $query = ''): string
    {
        return "api/core/print/forms/label-barcode/{$itemId}".($query === '' ? '' : "?{$query}");
    }

    public function test_the_sheet_encodes_the_item_code_when_the_card_has_no_supplier_barcode(): void
    {
        $item = $this->makeItem('Semen Portland 50kg', ['code' => 'ITM-0001', 'unit' => 'zak']);

        $html = $this->actingAs($this->adminUser(), 'sanctum')
            ->get($this->url($item->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('Form F/LBL', $html);
        $this->assertStringContainsString('aria-label="Barcode ITM-0001"', $html);
        $this->assertStringContainsString('kode item', $html);
        $this->assertStringNotContainsString('barcode pemasok</b>', $html);
    }

    public function test_the_sheet_encodes_the_supplier_barcode_when_there_is_one_and_says_so(): void
    {
        $item = $this->makeItem('Kabel UTP Cat6', ['code' => 'ITM-0003', 'barcode' => '8991002123458', 'unit' => 'roll']);

        $html = $this->actingAs($this->adminUser(), 'sanctum')
            ->get($this->url($item->id))
            ->assertOk()
            ->getContent();

        // Yang dikodekan adalah barcode pemasok…
        $this->assertStringContainsString('aria-label="Barcode 8991002123458"', $html);
        // …dan teks di bawah batang membawa KEDUANYA, karena yang dipindai
        // mesin dan yang dicari orang di layar adalah dua string berbeda.
        $this->assertStringContainsString('>ITM-0003 · 8991002123458</text>', $html);
        $this->assertStringContainsString('barcode pemasok', $html);
    }

    /**
     * ATURAN KEJUJURAN, bentuk paling tajamnya: bukan sel kosong melainkan
     * TIDAK ADA GAMBAR, dengan kalimat yang menyebut kodenya.
     */
    public function test_a_code_code_128_cannot_carry_prints_no_bars_and_says_why(): void
    {
        $item = $this->makeItem('Pipa Ø 4 inci', ['code' => 'ITM-Ø004', 'unit' => 'btg']);

        $html = $this->actingAs($this->adminUser(), 'sanctum')
            ->get($this->url($item->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<svg', $html, 'Sebuah gambar barcode dari kode yang tidak bisa dikodekan terbaca sebagai kode LAIN.');
        $this->assertStringContainsString('Barcode tidak dicetak', $html);
        $this->assertStringContainsString('ITM-Ø004', $html);
        // Stikernya TETAP dicetak, dengan garis untuk ditulis tangan.
        $this->assertStringContainsString('tanpa-barcode', $html);
    }

    public function test_the_number_of_stickers_follows_the_url_and_defaults_to_twelve(): void
    {
        $item = $this->makeItem('Pasir Beton', ['code' => 'ITM-0005', 'unit' => 'm3']);
        $admin = $this->adminUser();

        $default = $this->actingAs($admin, 'sanctum')->get($this->url($item->id))->assertOk()->getContent();
        $this->assertSame(12, substr_count($default, 'class="stiker"'));
        $this->assertStringContainsString('12 label', $default);

        $four = $this->actingAs($admin, 'sanctum')->get($this->url($item->id, 'jumlah=4'))->assertOk()->getContent();
        $this->assertSame(4, substr_count($four, 'class="stiker"'));
    }

    /**
     * DITOLAK di luar rentang, bukan dijepit diam-diam: 60 stiker yang datang
     * setelah seseorang mengetik 500 terbaca sebagai kegagalan cetak.
     */
    public function test_a_sticker_count_outside_the_range_is_refused(): void
    {
        $item = $this->makeItem('Pasir Beton', ['code' => 'ITM-0006', 'unit' => 'm3']);
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')->getJson($this->url($item->id, 'jumlah=500'))->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->getJson($this->url($item->id, 'jumlah=0'))->assertStatus(422);
    }

    /** Mencetak adalah membaca dalam bentuk lain: izinnya inv.view, milik pemilik recordnya. */
    public function test_the_sheet_carries_the_inventory_view_permission(): void
    {
        $item = $this->makeItem('Pasir Beton', ['code' => 'ITM-0007', 'unit' => 'm3']);

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('tanpa-inv', 'web');
        $role->syncPermissions(Permission::query()->whereIn('name', ['prj.view'])->get());

        /** @var User $outsider */
        $outsider = User::query()->create([
            'name' => 'Tanpa Persediaan', 'email' => 'luar@test.local', 'password' => 'password', 'is_active' => true,
        ]);
        $outsider->assignRole($role);

        $this->actingAs($outsider, 'sanctum')->getJson($this->url($item->id))->assertForbidden();
    }

    public function test_an_item_that_does_not_exist_is_a_404_not_a_blank_sheet(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson($this->url(999999))
            ->assertNotFound();
    }

    /**
     * Item yang sudah dibuang tetap bisa dicetak labelnya — barangnya masih
     * ada di rak, dan justru itulah saat orang mencari labelnya. Pola
     * withTrashed yang sama dengan setiap belongsTo pada lembar rumah lain.
     */
    public function test_a_soft_deleted_item_can_still_have_its_label_printed(): void
    {
        $item = $this->makeItem('Item Lama', ['code' => 'ITM-0099', 'unit' => 'unit']);
        $item->delete();

        $this->actingAs($this->adminUser(), 'sanctum')
            ->get($this->url($item->id))
            ->assertOk()
            ->assertSee('ITM-0099', false);
    }
}
