<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Modules\Inventory\Models\Item;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * "KODE MANA YANG DIANGGAP SAMA" — satu aturan, tiga permukaan (F-6, putaran
 * kedua).
 *
 * ======================================================================
 * KENAPA BERKAS INI ADA.
 *
 * Aturan pencocokan kode item dulu ditulis TIGA KALI, dan ketiganya
 * berbeda:
 *
 *   layar Pindai        UPPER(barcode)=? OR UPPER(code)=?     (tanpa terbuang)
 *   lembar F/LBL        barcode=? OR code=?  + withTrashed()  (peka huruf)
 *   saringan "ganda"    GROUP BY barcode HAVING COUNT(*)>1    (barcode saja)
 *
 * Akibatnya bisa diukur, dan ketiganya sampai ke tangan orang:
 *
 *   `F6DUP001` vs `f6dup001` → Pindai bilang GANDA, lembarnya diam;
 *   barcode item 5 = KODE item 2 → Pindai bilang GANDA, kedua lembarnya
 *   memperingatkan, dan saringan audit memulangkan NOL BARIS — pemilik
 *   membaca "Tidak ada data", menyimpulkan katalognya bersih, dan
 *   memutuskan `inv_items.barcode` boleh dijadikan UNIQUE. Migrasi itu
 *   lalu gagal saat deploy.
 *
 * Sekarang ketiganya bertanya kepada SATU ekspresi (`Item::matchingScanCode`
 * dan `Item::sharingScanCode`, yang dibangun dari daftar kolom dan bentuk
 * perbandingan yang sama). Uji ini memaku KESETARAANNYA, bukan ketiga
 * jawabannya satu per satu: sebuah salinan keempat yang menyimpang harus
 * membuat berkas ini merah.
 * ======================================================================
 *
 * PERTANYAANNYA BERBEDA, ATURANNYA SATU:
 *
 *  - Pindai bertanya tentang kode yang DIKETIK atau dipindai;
 *  - lembar F/LBL bertanya tentang kode yang SEDANG IA CETAK (barcode
 *    pemasok bila ada, kode item bila tidak) — karena kalimatnya berjanji
 *    "memindai stiker INI akan memulangkan lebih dari satu item";
 *  - saringan audit bertanya tentang SETIAP kode yang item itu jawab
 *    (kodenya sendiri DAN barcode-nya), karena yang diaudit adalah
 *    katalognya, bukan satu stiker.
 */
class ScanCodeParityTest extends ErpTestCase
{
    use InventoryFixtures;

    private ?User $user = null;

    private function admin(): User
    {
        return $this->user ??= $this->adminUser();
    }

    /** @return list<string> kode item yang dipulangkan sebuah pemindaian */
    private function scanned(string $code): array
    {
        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('api/inventory/items/scan?code='.urlencode($code))
            ->assertOk()
            ->json('data');

        return array_column($payload['matches'], 'code');
    }

    private function sheetWarns(Item $item): bool
    {
        $html = $this->actingAs($this->admin(), 'sanctum')
            ->get("api/core/print/forms/label-barcode/{$item->id}?jumlah=1")
            ->assertOk()
            ->getContent();

        return str_contains($html, 'Kode ini tidak unik');
    }

    /** @return list<string> */
    private function auditFilter(bool $duplicate): array
    {
        $rows = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('api/inventory/items?per_page=100&barcode_duplicate='.($duplicate ? 1 : 0))
            ->assertOk()
            ->json('data');

        return array_column($rows, 'code');
    }

    /** Kode yang BENAR-BENAR dicetak pada stiker item ini. */
    private function printedCode(Item $item): string
    {
        $supplier = trim((string) $item->barcode);

        return $supplier !== '' ? $supplier : (string) $item->code;
    }

    /**
     * SATU HURUF BESAR YANG BUKAN IA KETIK.
     *
     * Pindai berhenti peka huruf pada 05625ef karena papan ketik iOS
     * mengapitalkan huruf pertama; lembar F/LBL tidak ikut, jadi ia DIAM
     * untuk tabrakan yang pemindainya sebut ganda — persis pada permukaan
     * yang bisa mencegahnya, sebelum stikernya menempel di rak.
     */
    public function test_the_letter_case_a_keyboard_changed_is_one_collision_on_every_surface(): void
    {
        $first = $this->makeItem('Semen A', ['code' => 'ITM-C001', 'barcode' => 'F6DUP001']);
        $second = $this->makeItem('Semen B', ['code' => 'ITM-C002', 'barcode' => 'f6dup001']);

        $this->assertEqualsCanonicalizing(['ITM-C001', 'ITM-C002'], $this->scanned('F6DUP001'),
            'Pindai sudah memulangkan keduanya sejak 05625ef.');

        $this->assertTrue($this->sheetWarns($first), 'Lembar F/LBL diam untuk tabrakan yang pemindainya sebut ganda.');
        $this->assertTrue($this->sheetWarns($second));

        $this->assertEqualsCanonicalizing(['ITM-C001', 'ITM-C002'], $this->auditFilter(true));
        $this->assertSame([], $this->auditFilter(false));
    }

    /**
     * BARCODE SEBUAH ITEM ADALAH KODE ITEM LAIN — tabrakan yang saringan
     * audit tidak pernah bisa melihat, karena ia hanya membandingkan
     * barcode dengan barcode.
     *
     * Inilah bentuk yang paling mahal: pemilik membaca "Tidak ada data" pada
     * saringannya, menyimpulkan katalognya bersih, dan menyetujui UNIQUE.
     */
    public function test_a_barcode_that_is_another_items_code_is_one_collision_on_every_surface(): void
    {
        $named = $this->makeItem('Besi Beton D16', ['code' => 'ITM-C010']);
        $borrower = $this->makeItem('Besi Impor', ['code' => 'ITM-C011', 'barcode' => 'ITM-C010']);
        $this->makeItem('Pasir Beton', ['code' => 'ITM-C012', 'barcode' => 'F6UNIQ01']);

        $this->assertEqualsCanonicalizing(['ITM-C010', 'ITM-C011'], $this->scanned('ITM-C010'));

        $this->assertTrue($this->sheetWarns($named));
        $this->assertTrue($this->sheetWarns($borrower));

        $this->assertEqualsCanonicalizing(['ITM-C010', 'ITM-C011'], $this->auditFilter(true),
            'Audit yang membandingkan barcode dengan barcode saja BUTA terhadap tabrakan ini.');
        $this->assertSame(['ITM-C012'], $this->auditFilter(false));
    }

    /**
     * ITEM YANG DIBUANG BUKAN KEMBARAN.
     *
     * Pindai tidak pernah memulangkannya (`test_a_soft_deleted_item_is_not_
     * scannable`), jadi lembar yang memperingatkan "memindai stiker ini akan
     * memulangkan lebih dari satu item" karena sebuah kartu yang sudah dibuang
     * sedang berjanji tentang sesuatu yang tidak akan terjadi.
     */
    public function test_a_thrown_away_twin_is_a_collision_on_none_of_them(): void
    {
        $live = $this->makeItem('Semen Hidup', ['code' => 'ITM-C020', 'barcode' => 'F6DUP020']);
        $gone = $this->makeItem('Semen Lama', ['code' => 'ITM-C021', 'barcode' => 'F6DUP020']);
        $gone->delete();

        $this->assertSame(['ITM-C020'], $this->scanned('F6DUP020'));
        $this->assertFalse($this->sheetWarns($live),
            'Lembarnya menjanjikan pemindaian ganda yang tidak akan pernah terjadi.');
        $this->assertSame([], $this->auditFilter(true));
    }

    /**
     * SAPUAN: setiap item di katalog, dan ketiga permukaan menjawab hal yang
     * sama tentangnya.
     *
     * Ini uji yang menangkap salinan KEEMPAT: sebuah permukaan yang menyimpang
     * pada satu bentuk tabrakan saja tetap merah di sini, tanpa berkas ini
     * pernah menyebut bentuk itu.
     */
    public function test_every_item_in_the_catalogue_gets_the_same_answer_from_all_three_surfaces(): void
    {
        $catalogue = [
            // kode unik, tanpa barcode → tidak bertabrakan dengan apa pun
            $this->makeItem('Tunggal', ['code' => 'ITM-S001']),
            // dua barcode yang sama, beda huruf besar-kecil
            $this->makeItem('Ganda A', ['code' => 'ITM-S002', 'barcode' => 'F6SWEEP1']),
            $this->makeItem('Ganda B', ['code' => 'ITM-S003', 'barcode' => 'f6sweep1']),
            // barcode = kode item lain
            $this->makeItem('Dipinjam', ['code' => 'ITM-S004']),
            $this->makeItem('Peminjam', ['code' => 'ITM-S005', 'barcode' => 'ITM-S004']),
            // barcode unik
            $this->makeItem('Sendiri', ['code' => 'ITM-S006', 'barcode' => '8991002123458']),
            // barcode kosong dan barcode berisi spasi BUKAN kode yang sama
            $this->makeItem('Kosong A', ['code' => 'ITM-S007', 'barcode' => '']),
            $this->makeItem('Kosong B', ['code' => 'ITM-S008', 'barcode' => '   ']),
            // kode yang tidak bisa dijadikan Code 128 tetap punya aturan yang sama
            $this->makeItem('Tak Terkodekan A', ['code' => 'ITM-Ø009', 'barcode' => 'KODE—Ø']),
            $this->makeItem('Tak Terkodekan B', ['code' => 'ITM-Ø010', 'barcode' => 'KODE—Ø']),
        ];

        $expectedDuplicates = [];

        foreach ($catalogue as $item) {
            $printed = $this->printedCode($item);

            // 1. LEMBARNYA memperingatkan persis ketika PEMINDAIAN kode yang
            //    ia cetak memulangkan lebih dari satu item.
            $scanIsAmbiguous = count($this->scanned($printed)) > 1;
            $this->assertSame(
                $scanIsAmbiguous,
                $this->sheetWarns($item),
                "Lembar {$item->code} dan layar Pindai tidak sepakat tentang kode \"{$printed}\".",
            );

            // 2. AUDITNYA memuat item ini persis ketika salah satu kode yang
            //    ia jawab dipakai item lain juga.
            $keys = array_values(array_filter([$item->code, trim((string) $item->barcode)], fn ($key) => $key !== ''));
            $shared = false;

            foreach ($keys as $key) {
                $shared = $shared || count($this->scanned($key)) > 1;
            }

            if ($shared) {
                $expectedDuplicates[] = $item->code;
            }
        }

        $this->assertEqualsCanonicalizing($expectedDuplicates, $this->auditFilter(true),
            'Saringan audit memakai aturan yang berbeda dari pemindainya.');

        $this->assertEqualsCanonicalizing(
            array_values(array_diff(array_map(fn (Item $item) => $item->code, $catalogue), $expectedDuplicates)),
            $this->auditFilter(false),
            'Lengan "Tidak" harus memuat SISANYA — termasuk item tanpa barcode, yang `NOT IN` diam-diam buang.',
        );
    }
}
