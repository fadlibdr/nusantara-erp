<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Support\Facades\DB;
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
     *
     * DAN ITU BERLAKU KE DUA ARAH. Lembar milik kartu yang DIBUANG adalah
     * jalur yang sengaja didukung (`test_a_soft_deleted_item_can_still_have_
     * its_label_printed`), dan sampai putaran ketiga F-6 ia menghitung
     * kembarannya seolah subjeknya masih ikut dipindai: satu kartu hidup
     * dengan kode yang sama membuatnya berkata "akan memulangkan lebih dari
     * satu item" sementara layar Pindai berkata "Satu item cocok". Kertas yang
     * menjanjikan pemindaian ganda yang tidak akan pernah terjadi adalah
     * kertas yang membuat orang membuang label yang benar.
     */
    public function test_a_thrown_away_twin_is_a_collision_on_none_of_them(): void
    {
        $live = $this->makeItem('Semen Hidup', ['code' => 'ITM-C020', 'barcode' => 'F6DUP020']);
        $gone = $this->makeItem('Semen Lama', ['code' => 'ITM-C021', 'barcode' => 'F6DUP020']);
        $gone->delete();

        $this->assertSame(['ITM-C020'], $this->scanned('F6DUP020'));
        $this->assertFalse($this->sheetWarns($live),
            'Lembarnya menjanjikan pemindaian ganda yang tidak akan pernah terjadi.');
        $this->assertFalse($this->sheetWarns($gone),
            'Lembar kartu yang DIBUANG memperingatkan pemindaian ganda yang layar Pindai sebut tunggal.');
        $this->assertSame([], $this->auditFilter(true));
    }

    /**
     * …DAN LEMBAR KARTU TERBUANG TETAP MEMPERINGATKAN KETIKA PEMINDAIANNYA
     * MEMANG GANDA.
     *
     * Yang dijanjikan kalimatnya adalah keadaan PEMINDAIAN kode itu, bukan
     * jumlah kartu yang memakainya: dua kartu hidup dengan kode yang sama
     * membuat pemindaian ambigu, dan stiker yang sedang dicetak dari kartu
     * ketiga yang sudah dibuang tetap menempel di rak dengan kode itu.
     */
    public function test_a_thrown_away_sheet_still_warns_when_the_scan_really_is_ambiguous(): void
    {
        $first = $this->makeItem('Semen Hidup A', ['code' => 'ITM-C030', 'barcode' => 'F6DUP030']);
        $second = $this->makeItem('Semen Hidup B', ['code' => 'ITM-C031', 'barcode' => 'F6DUP030']);
        $gone = $this->makeItem('Semen Lama', ['code' => 'ITM-C032', 'barcode' => 'F6DUP030']);
        $gone->delete();

        $this->assertEqualsCanonicalizing(['ITM-C030', 'ITM-C031'], $this->scanned('F6DUP030'));
        $this->assertTrue($this->sheetWarns($gone));
        $this->assertTrue($this->sheetWarns($first));
        $this->assertTrue($this->sheetWarns($second));
    }

    /**
     * DUA KODE YANG BERBEDA HANYA PADA HURUF BERAKSEN ADALAH DUA ITEM — DI
     * KEDUA MESIN (F-6, putaran ketiga).
     *
     * `UPPER()` menutup selisih huruf besar-kecil ASCII; ia TIDAK menetralkan
     * collation. Kolomnya `utf8mb4_unicode_ci`, dan di MySQL 8 ekspresi
     * `UPPER('café') = 'CAFE'` memulangkan 1. Terukur sebelum perbaikan,
     * dengan dua kartu berbarcode `CAFÉ-2026` dan `CAFE-2026`:
     *
     *   SQLITE                              MYSQL 8.0.46
     *   pindai 'CAFE-2026' → satu: E002     pindai 'CAFE-2026' → ambiguous: E001, E002
     *   saringan ganda     → (kosong)       saringan ganda     → E001, E002
     *
     * Yaitu persis selisih yang `UPPER()` dipasang untuk menutup, pada
     * permukaan yang seluruh gunanya adalah pembacaan mesin yang TEPAT: satu
     * pemindaian yang memulangkan dua kartu karena aksen memaksa orang gudang
     * memilih sendiri antara dua barang yang kodenya memang berbeda.
     *
     * Uji ini harus dijalankan di KEDUA driver — di SQLite ia sudah hijau
     * sebelum perbaikannya, dan yang membuktikan perbaikannya adalah
     * `DB_DATABASE=erp_dryrun vendor/bin/phpunit -c phpunit.mysql.xml`.
     */
    public function test_two_codes_that_differ_only_by_an_accent_are_two_items_on_every_driver(): void
    {
        $accented = $this->makeItem('Keramik Impor', ['code' => 'ITM-E001', 'barcode' => 'CAFÉ-2026']);
        $plain = $this->makeItem('Keramik Lokal', ['code' => 'ITM-E002', 'barcode' => 'CAFE-2026']);

        $this->assertSame(['ITM-E002'], $this->scanned('CAFE-2026'),
            'Pemindaian memulangkan kartu yang kodenya beraksen: collation kolom yang membandingkan, bukan aturannya.');
        $this->assertSame(['ITM-E001'], $this->scanned('CAFÉ-2026'));

        $this->assertFalse($this->sheetWarns($accented),
            'Lembar labelnya memperingatkan kembaran yang bukan kembaran.');
        $this->assertFalse($this->sheetWarns($plain));

        $this->assertSame([], $this->auditFilter(true),
            'Saringan audit menyebut tabrakan yang tidak ada, dan pemilik yang memutuskan UNIQUE membacanya.');
        $this->assertEqualsCanonicalizing(['ITM-E001', 'ITM-E002'], $this->auditFilter(false));
    }

    /**
     * …DAN HURUF BESAR-KECIL ASCII TETAP SATU KODE, di kedua mesin. Inilah
     * yang `UPPER()` memang tutup, dan yang tidak boleh ikut hilang bersama
     * perbaikan aksen di atas: papan ketik iOS mengapitalkan huruf pertama.
     */
    public function test_ascii_letter_case_is_still_one_code_on_every_driver(): void
    {
        $this->makeItem('Semen A', ['code' => 'ITM-E010', 'barcode' => 'f6case10']);
        $this->makeItem('Semen B', ['code' => 'ITM-E011', 'barcode' => 'F6CASE10']);

        $this->assertEqualsCanonicalizing(['ITM-E010', 'ITM-E011'], $this->scanned('f6CaSe10'));
        $this->assertEqualsCanonicalizing(['ITM-E010', 'ITM-E011'], $this->auditFilter(true));
    }

    /**
     * SARINGAN AUDIT HARUS BISA DIBUKA PADA KATALOG SUNGGUHAN.
     *
     * Bentuk pertama penyatuan ini adalah `EXISTS (… other …)` berkorelasi —
     * aturannya benar, dan ia mengubah layar audit menjadi layar yang tidak
     * bisa dibuka: diukur pada SQLite dengan 5.000 item, `count()` saringannya
     * **20.973 ms** untuk lengan "ganda" dan **21.921 ms** untuk lengan
     * "tidak". `listing()` menghitung total sebelum menggambar halaman
     * pertama, jadi itulah waktu yang dilihat orangnya — dan permukaan yang
     * butuh 21 detik adalah permukaan yang tidak dipakai untuk mengambil
     * keputusan apa pun.
     *
     * Sesudah kunci yang bertabrakan dihitung SEKALI: 23 ms pada katalog yang
     * sama. Anggaran di bawah adalah 2 detik untuk 2.000 item — bentuk
     * berkorelasi mendarat di ~3,4 detik pada ukuran itu, jadi kembalinya
     * bentuk itu MERAH di sini, sementara mesin yang sedang sibuk tidak.
     */
    public function test_the_audit_filter_answers_a_real_sized_catalogue_in_time_to_be_read(): void
    {
        $categoryId = $this->category()->id;
        $rows = [];

        for ($i = 1; $i <= 2000; $i++) {
            $rows[] = [
                'code' => 'ITM-P'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'name' => 'Barang '.$i,
                'category_id' => $categoryId,
                'unit' => 'zak',
                // 4 tabrakan yang disengaja, sisanya barcode unik.
                'barcode' => $i % 500 === 0 ? 'F6BIG-'.($i % 1000) : 'BC-'.$i,
                'item_type' => 'material',
                'min_stock' => 0, 'avg_cost' => 0, 'last_price' => 0, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('inv_items')->insert($chunk);
        }

        $started = microtime(true);
        $duplicates = Item::query()->sharingScanCode()->count();
        $unique = Item::query()->sharingScanCode(false)->count();
        $elapsed = microtime(true) - $started;

        // 4 item bertabrakan: dua pasang yang berbagi F6BIG-500 dan F6BIG-0.
        $this->assertSame(4, $duplicates);
        $this->assertSame(1996, $unique, 'Kedua lengan harus menutupi seluruh katalog, bukan sebagian.');

        $this->assertLessThan(2.0, $elapsed,
            sprintf('Saringan audit butuh %.1f detik untuk 2.000 item: yang dipakai adalah bentuk '
                .'berkorelasi (satu subkueri per baris), dan layarnya tidak bisa dibuka.', $elapsed));
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
