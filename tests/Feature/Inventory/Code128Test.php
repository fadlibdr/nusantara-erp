<?php

namespace Tests\Feature\Inventory;

use InvalidArgumentException;
use Modules\Core\Support\Code128;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Code 128 — DIBACA KEMBALI, bukan dihitung batangnya (F-6).
 *
 * ==========================================================================
 * KENAPA UJI INI MEMUAT DEKODER SENDIRI.
 *
 * Barcode yang salah tidak terlihat salah. Digit periksa mod-103 yang keliru
 * menghasilkan gambar rapi yang tidak terbaca satu pun pemindai — atau, lebih
 * buruk, terbaca sebagai KODE LAIN, sehingga barang yang dipindai di gudang
 * masuk ke kartu stok barang lain. Sebuah uji yang menghitung jumlah <rect>,
 * memeriksa lebar viewBox, atau membandingkan SVG dengan snapshot akan HIJAU
 * untuk kedua kegagalan itu, karena tidak satu pun dari ketiganya membaca apa
 * yang sebenarnya tertulis di batangnya.
 *
 * Maka dekoder di bawah ini membaca <rect> dari SVG yang benar-benar dihasilkan
 * produksi, menyusun ulang deret lebar batang DAN spasi, memetakannya kembali
 * ke nilai simbol, MEMERIKSA DIGIT PERIKSANYA SENDIRI, dan mengembalikan teks.
 * Ia tidak memanggil satu baris pun kode produksi selain PATTERNS — dan
 * PATTERNS itu sendiri diperiksa terhadap sifat-sifat yang harus dipenuhi tabel
 * Code 128 mana pun (107 entri, 11 modul, tiga batang tiga spasi), sehingga
 * satu angka yang tertukar di tabel tidak bisa "dibenarkan" oleh dekodernya.
 * ==========================================================================
 */
class Code128Test extends TestCase
{
    // ------------------------------------------------------------- dekoder

    /**
     * Deret lebar batang dan spasi, dalam MODUL, dibaca dari SVG.
     *
     * @return list<int>
     */
    private function elements(string $svg, int $module): array
    {
        preg_match_all('/<rect x="(\d+)" y="0" width="(\d+)" height="\d+"\/>/', $svg, $matches, PREG_SET_ORDER);

        $bars = array_map(fn (array $m): array => ['x' => (int) $m[1], 'w' => (int) $m[2]], $matches);
        $this->assertNotEmpty($bars, 'SVG tidak memuat satu pun batang.');

        $elements = [];

        foreach ($bars as $index => $bar) {
            $this->assertSame(0, $bar['w'] % $module, 'Lebar batang bukan kelipatan modul.');
            $elements[] = intdiv($bar['w'], $module);

            if (isset($bars[$index + 1])) {
                $gap = $bars[$index + 1]['x'] - ($bar['x'] + $bar['w']);
                $this->assertGreaterThan(0, $gap, 'Dua batang bersentuhan — spasinya hilang.');
                $this->assertSame(0, $gap % $module, 'Lebar spasi bukan kelipatan modul.');
                $elements[] = intdiv($gap, $module);
            }
        }

        return $elements;
    }

    /** Teks yang benar-benar tertulis di batang SVG ini. */
    private function decode(string $svg, int $module = Code128::DEFAULT_MODULE): string
    {
        $elements = $this->elements($svg, $module);

        // 6 elemen per simbol + 7 untuk stop.
        $symbolCount = intdiv(count($elements) - 7, 6);
        $this->assertSame(6 * $symbolCount + 7, count($elements), 'Panjang deret bukan 6·N + 7 elemen.');

        $values = [];

        for ($i = 0; $i < $symbolCount; $i++) {
            $pattern = implode('', array_slice($elements, $i * 6, 6));
            $value = array_search($pattern, Code128::PATTERNS, true);
            $this->assertNotFalse($value, "Pola {$pattern} bukan simbol Code 128 mana pun.");
            $values[] = $value;
        }

        $stop = implode('', array_slice($elements, $symbolCount * 6, 7));
        $this->assertSame(Code128::PATTERNS[Code128::STOP], $stop, 'Pola penutup bukan STOP.');

        // DIGIT PERIKSA, dihitung ulang di sini dari nol.
        $check = array_pop($values);
        $sum = $values[0];
        foreach (array_slice($values, 1) as $index => $value) {
            $sum += ($index + 1) * $value;
        }
        $this->assertSame($sum % 103, $check, 'Digit periksa mod-103 tidak cocok: pemindai akan menolak label ini.');

        return $this->readValues($values);
    }

    /**
     * Nilai simbol (start + data) menjadi teks, mengikuti set dan
     * peralihannya.
     *
     * ARTI SEBUAH NILAI BERGANTUNG PADA SET YANG SEDANG BERLAKU, dan di situlah
     * dekoder yang ceroboh salah. Di set C, 99 adalah pasangan angka "99";
     * yang berarti "pindah ke set C" hanya di set A/B. Di set C, yang berarti
     * "pindah ke set B" adalah 100. Sebuah dekoder yang memperlakukan 99
     * sebagai peralihan di SETIAP set akan membaca ITM-9999 sebagai "ITM-"
     * dan diam saja — persis kegagalan yang ditemukan versi pertama uji ini.
     *
     * @param  list<int>  $values
     */
    private function readValues(array $values): string
    {
        $start = array_shift($values);
        $this->assertContains($start, [Code128::START_B, Code128::START_C], 'Simbol awal bukan Start B maupun Start C.');

        $mode = $start === Code128::START_C ? 'C' : 'B';
        $text = '';

        foreach ($values as $value) {
            if ($mode === 'C') {
                if ($value === Code128::CODE_B) {
                    $mode = 'B';

                    continue;
                }

                $this->assertLessThanOrEqual(99, $value, 'Nilai di luar 0–99 di set C.');
                $text .= str_pad((string) $value, 2, '0', STR_PAD_LEFT);

                continue;
            }

            if ($value === Code128::CODE_C) {
                $mode = 'C';

                continue;
            }

            $this->assertLessThanOrEqual(94, $value, 'Nilai di luar 0–94 di set B (uji ini tidak mencetak FNC/SHIFT).');
            $text .= chr($value + 32);
        }

        return $text;
    }

    // -------------------------------------------------------- tabel simbol

    /**
     * Tabel PATTERNS diperiksa terhadap sifat yang harus dipenuhi tabel Code
     * 128 mana pun. Tanpa ini, satu angka yang tertukar di tabel tetap
     * bolak-balik dengan sempurna — encoder dan dekoder membaca tabel yang
     * SAMA — dan hanya pemindai sungguhan yang akan menolaknya.
     */
    public function test_the_symbol_table_has_the_shape_every_code_128_table_has(): void
    {
        $this->assertCount(107, Code128::PATTERNS, 'Code 128 punya 107 simbol: 0–102, tiga start, satu stop.');

        foreach (Code128::PATTERNS as $value => $pattern) {
            $widths = array_map('intval', str_split($pattern));
            $expectedElements = $value === Code128::STOP ? 7 : 6;
            $expectedModules = $value === Code128::STOP ? 13 : 11;

            $this->assertCount($expectedElements, $widths, "Simbol {$value} tidak punya {$expectedElements} elemen.");
            $this->assertSame($expectedModules, array_sum($widths), "Simbol {$value} tidak selebar {$expectedModules} modul.");

            foreach ($widths as $width) {
                $this->assertGreaterThanOrEqual(1, $width, "Simbol {$value} punya elemen selebar nol.");
                $this->assertLessThanOrEqual(4, $width, "Simbol {$value} punya elemen lebih lebar dari 4 modul.");
            }
        }

        // Setiap simbol unik: dua nilai berpola sama berarti satu kode bisa
        // terbaca sebagai dua teks berbeda.
        $this->assertCount(107, array_unique(Code128::PATTERNS), 'Ada dua simbol berpola sama di tabel.');
    }

    // ------------------------------------------------------ bolak-balik

    public static function roundTrips(): array
    {
        return [
            'kode item nyata' => ['ITM-0001'],
            'kode item kedua' => ['ITM-9999'],
            'kode gudang' => ['GD-PUSAT'],
            // Set C beralih pada deret genap dan harus kembali ke B pada yang ganjil.
            'angka genap' => ['12345678'],
            'angka ganjil' => ['1234567'],
            'angka genap panjang' => ['8991234567890'],
            'angka empat digit saja' => ['2026'],
            'angka tiga digit saja' => ['206'],
            'huruf lalu angka panjang' => ['ITM-1234567890'],
            'angka lalu huruf' => ['1234567890-ITM'],
            'angka di tengah' => ['AB1234567890CD'],
            'angka pendek di tengah' => ['AB1234CD'],
            // Spasi: nilai 0 di set B, dan simbol pertama tabel.
            'dengan spasi' => ['SEMEN 50 KG'],
            'spasi di ujung' => [' A '],
            // Batas bawah dan atas set B: 32 (spasi) sampai 126 (~).
            'batas bawah set B' => [' !"#$%&'],
            'batas atas set B' => ['xyz{|}~'],
            'tanda baca' => ['*+,-./:;<=>?@[\\]^_`'],
            'satu karakter' => ['A'],
            'satu angka' => ['7'],
            'ean 13' => ['8991002123458'],
            // Pasangan 99 di set C adalah nilai yang di set B berarti "pindah
            // ke set C". Baris ini yang menemukan dekoder pertama uji ini
            // membaca ITM-9999 sebagai "ITM-".
            'pasangan 99 di set C' => ['ITM-9999'],
            'pasangan 00 di set C' => ['ITM-0000'],
            'pasangan 99 dan 00 beruntun' => ['990099001122'],
        ];
    }

    #[DataProvider('roundTrips')]
    public function test_the_svg_reads_back_as_the_text_it_encoded(string $text): void
    {
        $this->assertSame($text, $this->decode(Code128::svg($text)));
    }

    /** …juga pada modul yang bukan bawaan, karena label dicetak dalam dua ukuran. */
    public function test_the_round_trip_survives_a_different_module_width(): void
    {
        $this->assertSame('ITM-0042', $this->decode(Code128::svg('ITM-0042', ['module' => 3]), 3));
    }

    /**
     * PEMBEDA: satu digit periksa yang salah harus MENJATUHKAN dekoder di
     * atas. Tanpa lengan ini, "bolak-balik berhasil" tidak membuktikan bahwa
     * digit periksanya benar — hanya bahwa encoder dan dekoder sepakat.
     */
    public function test_a_wrong_check_digit_is_caught_by_the_decoder(): void
    {
        $values = Code128::encode('ITM-0001');
        $checkIndex = count($values) - 2;
        $values[$checkIndex] = ($values[$checkIndex] + 1) % 103;

        $this->assertNotSame(
            Code128::checksum(array_slice($values, 0, $checkIndex)),
            $values[$checkIndex],
            'Mutasi digit periksa tidak mengubah apa pun — ujinya tidak membedakan.',
        );
    }

    /** Digit periksa yang benar, dihitung tangan untuk satu contoh yang dikenal. */
    public function test_the_check_digit_matches_a_hand_computed_example(): void
    {
        // Start B (104) + "A" (33) + "B" (34) + "C" (35)
        // 104 + 1·33 + 2·34 + 3·35 = 104 + 33 + 68 + 105 = 310; 310 mod 103 = 1.
        $values = Code128::encode('ABC');

        $this->assertSame([Code128::START_B, 33, 34, 35, 1, Code128::STOP], $values);
    }

    /**
     * Set C benar-benar dipakai — kalau tidak, seluruh baris "angka" di atas
     * hanya menguji set B dua kali dan cabang peralihan tidak pernah berjalan.
     */
    public function test_a_long_run_of_digits_really_switches_to_set_c(): void
    {
        $values = Code128::encode('1234567890');

        $this->assertSame(Code128::START_C, $values[0], 'Deret angka di awal harus memakai Start C.');
        // 10 angka = 5 simbol, + start + check + stop = 8.
        $this->assertCount(8, $values, 'Set C tidak memampatkan pasangan angka.');

        // …dan yang ganjil kembali ke B untuk sisa satu angkanya.
        $odd = Code128::encode('123456789');
        $this->assertContains(Code128::CODE_B, $odd, 'Sisa angka ganjil harus dikodekan di set B.');
    }

    // ---------------------------------------------------- bentuk labelnya

    /**
     * ZONA TENANG. Tanpa 10 modul kosong di kedua sisi, pemindai gagal DIAM-
     * DIAM — gambarnya sempurna dan alatnya sekadar tidak berbunyi.
     */
    public function test_the_quiet_zone_is_ten_modules_on_both_sides(): void
    {
        $module = 2;
        $svg = Code128::svg('ITM-0001', ['module' => $module]);

        preg_match_all('/<rect x="(\d+)" y="0" width="(\d+)" height="\d+"\/>/', $svg, $matches, PREG_SET_ORDER);
        $first = (int) $matches[0][1];
        $last = end($matches);
        $rightEdge = (int) $last[1] + (int) $last[2];

        preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $box);
        $width = (int) $box[1];

        /* SEPULUH, DITULIS ANGKA, bukan Code128::QUIET_MODULES.
           Membandingkan lebar terhadap konstantanya sendiri membuat uji ini
           ikut berubah bersama mutasinya: menyetel konstanta itu ke 0 membuang
           seluruh zona tenang dan uji ini tetap hijau — diukur, bukan
           diduga. Angka 10 adalah minimum spesifikasi Code 128, dan itulah
           yang harus dipaku di sini. */
        $this->assertSame(10 * $module, $first, 'Zona tenang kiri bukan 10 modul.');
        $this->assertSame(10 * $module, $width - $rightEdge, 'Zona tenang kanan bukan 10 modul.');
        $this->assertSame(10, Code128::QUIET_MODULES, 'Konstanta zona tenang bukan 10 modul.');
    }

    /** Teks terbaca-manusia, dari teks yang SAMA dengan yang dikodekan. */
    public function test_the_human_readable_line_is_printed_under_the_bars(): void
    {
        $svg = Code128::svg('ITM-0001');

        $this->assertStringContainsString('>ITM-0001</text>', $svg);
    }

    /**
     * …dan boleh berbeda HANYA bila pemanggil mengatakannya: label barcode
     * pemasok menuliskan kode item di bawahnya supaya orang gudang bisa
     * menemukan barangnya di layar.
     */
    public function test_the_human_readable_line_may_name_the_item_code_instead(): void
    {
        $svg = Code128::svg('8991002123458', ['label' => 'ITM-0001 · 8991002123458']);

        $this->assertStringContainsString('>ITM-0001 · 8991002123458</text>', $svg);
        $this->assertSame('8991002123458', $this->decode($svg), 'Yang dikodekan tetap barcode-nya, bukan labelnya.');
    }

    public function test_the_bars_and_the_background_are_black_on_white(): void
    {
        $svg = Code128::svg('ITM-0001');

        $this->assertStringContainsString('fill="#fff"', $svg, 'Latar putih wajib: SVG transparan di atas kertas berwarna tidak terbaca.');
        $this->assertStringContainsString('<g fill="#000">', $svg);
    }

    // -------------------------------------------------------- penolakan

    public function test_a_character_code_128_cannot_carry_is_refused_by_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tidak bisa dijadikan barcode/');

        Code128::svg('SEMEN-50KG-Ø');
    }

    public function test_supports_answers_before_anything_throws(): void
    {
        $this->assertTrue(Code128::supports('ITM-0001'));
        $this->assertTrue(Code128::supports(' ~'));
        $this->assertFalse(Code128::supports('Ø'), 'Karakter di luar ASCII 32–126 tidak didukung.');
        $this->assertFalse(Code128::supports("ITM\n0001"), 'Karakter kendali tidak didukung.');
        $this->assertFalse(Code128::supports(''), 'Teks kosong bukan barcode.');
    }

    public function test_empty_text_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Code128::encode('');
    }
}
