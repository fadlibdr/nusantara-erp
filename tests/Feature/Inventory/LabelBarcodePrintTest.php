<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Modules\Core\Support\Code128;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ------------------------------------------------- geometri di KERTAS

    /**
     * Lebar SVG dalam MILIMETER dari lembarnya, dan lebar kotak stikernya.
     *
     * @return array{svg_mm: float, sticker_mm: float, modules: int, module_mm: float, bar_height_mm: float}
     */
    private function geometry(string $html, string $encoded): array
    {
        $this->assertMatchesRegularExpression('/<svg[^>]+width="([\d.]+)mm"/', $html,
            'SVG lembar F/LBL tidak membawa lebar dalam milimeter, jadi lebar modul cetaknya diserahkan ke CSS.');
        preg_match('/<svg[^>]+width="([\d.]+)mm" height="([\d.]+)mm"/', $html, $svg);
        preg_match('/\.stiker \{.*?width: ([\d.]+)mm;/s', $html, $box);
        preg_match('/<rect x="\d+" y="0" width="\d+" height="(\d+)"\/>/', $html, $bar);
        preg_match('/viewBox="0 0 (\d+) (\d+)"/', $html, $view);

        $svgMm = (float) $svg[1];
        $modules = Code128::moduleCount($encoded);

        return [
            'svg_mm' => $svgMm,
            'svg_height_mm' => (float) $svg[2],
            'sticker_mm' => (float) $box[1],
            'modules' => $modules,
            'module_mm' => $svgMm / $modules,
            // Tinggi batang: satuan viewBox × (mm per satuan).
            'bar_height_mm' => (int) $bar[1] * ($svgMm / (int) $view[1]),
        ];
    }

    /**
     * TIGA ANGKA MILIMETER YANG MENENTUKAN APAKAH LABELNYA BERBUNYI.
     *
     * Sampai uji ini ada, ketiganya bisa dimutasi tanpa satu uji pun merah:
     * lebar stiker 62 mm → 120 mm, opsi `module` 2 → 6, tinggi batang 40 → 8
     * (yang memendekkan batang cetak menjadi 2,1 mm) semuanya LOLOS HIJAU pada
     * 71 uji / 5.963 asersi. Gejalanya baru muncul di gudang: label yang
     * tercetak sempurna dan tidak berbunyi di pemindai.
     *
     * Yang dipaku bukan piksel atribut SVG melainkan MILIMETER di kertas.
     */
    #[DataProvider('printedCodes')]
    public function test_the_printed_symbol_keeps_the_millimetres_a_scanner_needs(string $code, array $attributes, string $encoded, int $columns): void
    {
        $item = $this->makeItem('Barang Uji', ['code' => $code] + $attributes);

        $html = $this->actingAs($this->adminUser(), 'sanctum')->get($this->url($item->id))->assertOk()->getContent();
        $geometry = $this->geometry($html, $encoded);

        // 1. LEBAR MODUL ≥ 0,25 mm — di bawahnya batangnya menyatu di kertas.
        $this->assertGreaterThanOrEqual(
            Code128::MIN_MODULE_MM,
            round($geometry['module_mm'], 4),
            "Lebar modul cetak {$geometry['module_mm']} mm untuk {$encoded}: pemindai gagal DIAM-DIAM.",
        );

        // 2. SIMBOLNYA MUAT DI KOTAKNYA — kalau tidak, CSS yang mengecilkannya
        //    dan angka di atas menjadi bohong.
        $this->assertLessThanOrEqual(
            $geometry['sticker_mm'] - 5.0,
            round($geometry['svg_mm'], 3),
            'SVG lebih lebar daripada isi kotak stikernya: yang menentukan lebar batang menjadi CSS, bukan angka ini.',
        );

        // 3. TINGGI BATANG ≥ 15% LEBAR SIMBOL (dan ≥ 8 mm) — simbol lebar yang
        //    pendek adalah sehelai garis yang tidak bisa dilacak pemindai.
        $this->assertGreaterThanOrEqual(
            round(0.15 * $geometry['svg_mm'], 2) - 0.05,
            round($geometry['bar_height_mm'], 2),
            'Tinggi batang di bawah 15% lebar simbol.',
        );
        $this->assertGreaterThanOrEqual(7.95, round($geometry['bar_height_mm'], 2), 'Batang lebih pendek dari 8 mm.');

        // 4. KISINYA yang menyesuaikan, bukan gambarnya.
        $this->assertSame((float) [3 => 62.0, 2 => 95.0, 1 => 190.0][$columns], $geometry['sticker_mm']);
    }

    public static function printedCodes(): array
    {
        return [
            'kode item pendek' => ['ITM-0001', [], 'ITM-0001', 3],
            'barcode pemasok 13 digit' => ['ITM-0002', ['barcode' => '8991002123458'], '8991002123458', 3],
            // 22 karakter: modul 0,190 mm pada stiker 62 mm — di bawah minimum,
            // jadi kisinya harus jatuh ke dua kolom alih-alih mengecilkannya.
            'kode 22 karakter' => ['ITM-GUDANG-PUSAT-RAK-A', [], 'ITM-GUDANG-PUSAT-RAK-A', 2],
            // 33 karakter: 0,139 mm pada 62 mm dan masih di bawah minimum pada
            // 95 mm — satu stiker selebar halaman.
            'kode 33 karakter' => ['ITM-GUDANG-PUSAT-RAK-A-BARIS-0033', [], 'ITM-GUDANG-PUSAT-RAK-A-BARIS-0033', 1],
        ];
    }

    /**
     * …dan yang tetap tidak muat DITOLAK dengan kalimatnya, bukan dikecilkan.
     *
     * Barcode pemasok 100 karakter — persis batas yang diterima
     * ItemStoreRequest — mendarat pada modul 0,055 mm sebelum perbaikan ini:
     * sehelai garis abu-abu setinggi 1,1 mm yang masih terlihat seperti
     * barcode, dan 0 dari 5 garis pindai raster 600 dpi bisa membacanya.
     */
    public function test_a_code_too_long_to_stay_scannable_is_refused_by_name_not_shrunk(): void
    {
        $item = $this->makeItem('Barang Impor', [
            'code' => 'ITM-0100',
            'barcode' => str_repeat('A', 100),
        ]);

        $html = $this->actingAs($this->adminUser(), 'sanctum')->get($this->url($item->id))->assertOk()->getContent();

        $this->assertStringNotContainsString('<svg', $html, 'Barcode yang tidak terpindai lebih buruk daripada tidak ada barcode: ia terlihat seperti ada.');
        $this->assertStringContainsString('Barcode tidak dicetak', $html);
        $this->assertStringContainsString('100 karakter', $html);
        $this->assertStringContainsString('0,25 mm', $html);
        // Stikernya TETAP dicetak, dengan garis untuk ditulis tangan.
        $this->assertStringContainsString('tanpa-barcode', $html);
    }

    /**
     * TEKS TERBACA-MANUSIA TIDAK PERNAH TERPOTONG VIEWPORT — dan terpotongnya
     * DI KEDUA UJUNG, diam-diam.
     *
     * Barcode 13 digit di bawah kode item 40 karakter dulu tercetak sebagai 12
     * digit yang terlihat lengkap: orang gudang mengetik ulang `899100212345`
     * untuk barang ber-barcode `8991002123458`, sistem menjawab "tidak ada
     * item dengan kode itu", dan tidak ada apa pun di label yang mengatakan
     * bahwa yang dibacanya sudah terpotong.
     */
    public function test_a_label_longer_than_its_bars_is_broken_into_lines_not_clipped(): void
    {
        $item = $this->makeItem('Barang Rak Panjang', [
            // 40 karakter — batas `code` di ItemStoreRequest.
            'code' => 'ITM-GUDANG-PUSAT-RAK-AXXXXXXXXXXXXXXXXXX',
            'barcode' => '8991002123458',
        ]);

        $html = $this->actingAs($this->adminUser(), 'sanctum')->get($this->url($item->id, 'jumlah=1'))->assertOk()->getContent();

        preg_match('/viewBox="0 0 (\d+) (\d+)"/', $html, $view);
        preg_match_all('/<text [^>]*font-size="(\d+)"[^>]*>([^<]*)<\/text>/', $html, $texts, PREG_SET_ORDER);

        $this->assertNotEmpty($texts, 'Lembar tanpa teks terbaca-manusia.');
        $this->assertGreaterThan(1, count($texts), 'Label 56 karakter harus dipatahkan, bukan dijejalkan ke satu baris.');

        $whole = '';
        foreach ($texts as $line) {
            // 0,62 em per karakter monospace — perkiraan yang sama dengan yang
            // dipakai Code128::wrapLabel untuk memutuskan patahannya.
            $estimated = mb_strlen(html_entity_decode($line[2], ENT_QUOTES, 'UTF-8')) * (int) $line[1] * 0.62;
            $this->assertLessThanOrEqual((int) $view[1], (int) ceil($estimated),
                "Baris teks \"{$line[2]}\" lebih lebar daripada viewBox: akar SVG memotongnya di kedua ujung, diam-diam.");
            $whole .= html_entity_decode($line[2], ENT_QUOTES, 'UTF-8');
        }

        // Dan yang tercetak tetap kodenya UTUH — termasuk digit terakhirnya.
        $this->assertSame('ITM-GUDANG-PUSAT-RAK-AXXXXXXXXXXXXXXXXXX · 8991002123458', $whole);
    }

    /**
     * `.lembar` ADALAH KONTRAK ANTARA PHP DAN print.js, bukan gaya.
     *
     * printWhenLoaded() menunggu `tab.document.querySelector('.lembar')`
     * sebelum memanggil tab.print(): readyState saja tidak cukup, karena
     * about:blank sudah 'complete'. Tanpa pembungkus itu, lembar F/LBL
     * tergambar sempurna di tab barunya dan dialog cetak TIDAK PERNAH muncul —
     * tanpa satu pun pesan, yang di gudang terbaca sebagai "tombol cetaknya
     * rusak". Diukur di Chromium: print_calls=0 untuk F/LBL, 1 untuk GRN.
     */
    public function test_the_sheet_carries_the_wrapper_print_js_waits_for(): void
    {
        $item = $this->makeItem('Semen Portland 50kg', ['code' => 'ITM-0008', 'unit' => 'zak']);

        $html = $this->actingAs($this->adminUser(), 'sanctum')->get($this->url($item->id))->assertOk()->getContent();

        $this->assertStringContainsString('<div class="lembar">', $html,
            'print.js menunggu .lembar sebelum memanggil print(); tanpanya dialog cetak tidak pernah muncul.');

        $polled = (string) file_get_contents(public_path('app/js/print.js'));
        $this->assertStringContainsString(".querySelector('.lembar')", $polled,
            'print.js tidak lagi menunggu .lembar — kontraknya pindah dan lembar ini harus ikut.');
    }

    // ------------------------------------------------- barcode pemasok kosong

    /**
     * BARCODE BERISI SPASI SAJA BUKAN BARCODE.
     *
     * `trim()` di labelBarcode() adalah yang membuatnya jatuh kembali ke kode
     * item; mencabutnya lolos hijau sebelum uji ini ada, dan lembarnya lalu
     * mencetak kalimat "…yaitu barcode pemasok yang tercatat pada kartu item
     * ini" di atas barcode tiga spasi yang tidak cocok dengan apa pun.
     * TrimStrings bawaan Laravel menutup jalur API; data impor, seed dan
     * warisan tidak lewat sana.
     */
    public function test_a_supplier_barcode_of_only_spaces_falls_back_to_the_item_code(): void
    {
        $item = $this->makeItem('Besi Beton D16', ['code' => 'ITM-0009', 'barcode' => '   ', 'unit' => 'btg']);

        $html = $this->actingAs($this->adminUser(), 'sanctum')->get($this->url($item->id))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Barcode ITM-0009"', $html);
        $this->assertStringContainsString('kode item', $html);
        $this->assertStringNotContainsString('barcode pemasok</b>', $html);
    }

    /**
     * …tetapi spasi DI TEPI barcode sungguhan dibuang, bukan dikodekan: spasi
     * adalah karakter set B yang sah, jadi " 8991002123458" dan
     * "8991002123458" adalah dua barcode yang BERBEDA di mata pemindai.
     */
    public function test_a_supplier_barcode_with_edge_spaces_is_encoded_without_them(): void
    {
        $item = $this->makeItem('Kabel UTP Cat6', ['code' => 'ITM-0010', 'barcode' => ' 8991002123458 ', 'unit' => 'roll']);

        $html = $this->actingAs($this->adminUser(), 'sanctum')->get($this->url($item->id))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Barcode 8991002123458"', $html);
        $this->assertStringNotContainsString('aria-label="Barcode  8991002123458 "', $html);
    }

    // --------------------------------------------------------- barcode ganda

    /**
     * DUPLIKAT TERLIHAT DI PERMUKAAN YANG MENEMPELKANNYA DI RAK.
     *
     * Layar pindai memang sudah berkata "2 ITEM memakai kode yang sama" —
     * tetapi ia mengatakannya berbulan kemudian, ketika seseorang memindai
     * stiker yang sudah menempel. Klaim "paket ini membuat duplikatnya
     * TERLIHAT" hanya berlaku pada satu permukaan sampai lembar ini ikut
     * mengatakannya.
     */
    public function test_a_barcode_two_items_share_is_named_on_the_sheet_before_the_stickers_are_glued(): void
    {
        $first = $this->makeItem('Semen A', ['code' => 'ITM-0011', 'barcode' => 'F6DUP001', 'unit' => 'zak']);
        $this->makeItem('Semen B', ['code' => 'ITM-0012', 'barcode' => 'F6DUP001', 'unit' => 'zak']);

        $html = $this->actingAs($this->adminUser(), 'sanctum')->get($this->url($first->id))->assertOk()->getContent();

        $this->assertStringContainsString('Kode ini tidak unik', $html);
        $this->assertStringContainsString('ITM-0012', $html, 'Lembar harus MENYEBUT item lain yang memakai kode itu.');
    }

    /** …dan lembar item yang kodenya memang unik tidak menakut-nakuti siapa pun. */
    public function test_a_unique_barcode_prints_no_duplicate_warning(): void
    {
        $item = $this->makeItem('Semen Tunggal', ['code' => 'ITM-0013', 'barcode' => 'F6UNIQ01', 'unit' => 'zak']);

        $html = $this->actingAs($this->adminUser(), 'sanctum')->get($this->url($item->id))->assertOk()->getContent();

        $this->assertStringNotContainsString('Kode ini tidak unik', $html);
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
