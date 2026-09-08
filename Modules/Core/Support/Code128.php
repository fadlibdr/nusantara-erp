<?php

namespace Modules\Core\Support;

use InvalidArgumentException;

/**
 * Code 128 sebagai SVG, tanpa satu pun dependensi (F-6).
 *
 * ==========================================================================
 * KENAPA BERKAS INI ADA, DAN KENAPA IA BEGITU BERHATI-HATI.
 *
 * Barcode yang SALAH tidak terlihat salah. Digit periksa mod-103 yang keliru
 * menghasilkan gambar yang rapi, sejajar, dan tidak terbaca satu pun pemindai
 * — atau, lebih buruk, terbaca sebagai KODE LAIN, dan barang yang dipindai di
 * gudang masuk ke kartu stok barang lain. Sebuah uji yang menghitung jumlah
 * batang akan hijau untuk kedua kegagalan itu.
 *
 * Karena itu ujinya (tests/Feature/Inventory/Code128Test) memuat DEKODER: ia
 * membaca kembali pola batang SVG menjadi teks dan menuntut perjalanan
 * bolak-balik untuk kode item nyata, angka genap dan ganjil (peralihan set C),
 * spasi, dan karakter di batas set B.
 * ==========================================================================
 *
 * SET B SEBAGAI DASAR, SET C UNTUK DERET ANGKA.
 *
 * Yang dicetak label gudang adalah kode item (ITM-0001) atau barcode pemasok —
 * huruf besar, angka, tanda hubung, kadang spasi. Set B mencakup seluruh ASCII
 * 32–126 dalam satu simbol per karakter; set C memampatkan sepasang angka
 * menjadi satu simbol, yang memendekkan label angka panjang hampir separuh.
 * Set A (karakter kendali) TIDAK dipakai dan tidak dibuat: tidak ada yang
 * mencetaknya, dan sebuah cabang yang tidak pernah dijalankan adalah cabang
 * yang tidak pernah diuji.
 *
 * ZONA TENANG WAJIB, DAN ITU BUKAN HIASAN.
 *
 * Pemindai membutuhkan ruang kosong minimal 10 modul di kiri dan kanan untuk
 * menemukan tepi kode. Tanpa itu ia gagal DIAM-DIAM: gambarnya sempurna di
 * layar dan pemindainya sekadar tidak berbunyi, yang di gudang terbaca sebagai
 * "pemindainya rusak". Zona itu ikut ke dalam lebar viewBox, jadi ia tidak bisa
 * hilang karena tata letak halaman yang memepetkan gambarnya.
 *
 * TEKS TERBACA-MANUSIA WAJIB, alasan yang sama dari arah sebaliknya: label
 * yang batangnya tergores, terkena minyak, atau tercetak buram masih harus
 * bisa diketik orangnya. Ia dicetak di bawah batang, dari teks yang SAMA
 * dengan yang dikodekan — bukan dari kolom lain yang bisa berbeda.
 */
final class Code128
{
    /**
     * Lebar batang/spasi tiap nilai simbol, 0–106.
     *
     * Enam angka per simbol (batang, spasi, batang, spasi, batang, spasi),
     * berjumlah 11 modul; 106 adalah STOP dan punya TUJUH (13 modul), karena
     * kode ditutup batang, bukan spasi.
     *
     * 103/104/105 adalah Start A/B/C. 99/100/101 adalah peralihan set
     * (Code C / Code B / Code A) bila muncul SESUDAH start.
     */
    public const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    public const CODE_C = 99;

    public const CODE_B = 100;

    public const START_B = 104;

    public const START_C = 105;

    public const STOP = 106;

    /** Modul terkecil (satu satuan lebar batang) dalam piksel, bawaan. */
    public const DEFAULT_MODULE = 2;

    /** Zona tenang dalam MODUL, di kiri dan di kanan. Minimum spesifikasi. */
    public const QUIET_MODULES = 10;

    /**
     * LEBAR MODUL TERKECIL YANG MASIH TERPINDAI DI KERTAS, dalam MILIMETER.
     *
     * 0,25 mm adalah X-dimension minimum GS1 untuk cetak umum. Di bawahnya
     * batang-batangnya menyatu pada raster cetak dan pemindai gagal DIAM-DIAM:
     * labelnya tercetak rapi, alatnya sekadar tidak berbunyi, dan di gudang itu
     * terbaca sebagai "pemindainya rusak" — bukan sebagai "labelnya salah
     * cetak". Diukur pada lembar F/LBL sebelum paket perbaikan ini: barcode
     * pemasok 100 karakter mendarat pada modul 0,055 mm dan 0 dari 5 garis
     * pindai raster 600 dpi bisa membacanya, sementara seluruh uji hijau.
     */
    public const MIN_MODULE_MM = 0.25;

    /**
     * Lebar satu karakter monospace sebagai kelipatan fontSize. DejaVu Sans
     * Mono, Liberation Mono dan Courier semuanya 0,60–0,61; 0,62 memberi
     * sedikit ruang supaya perkiraan ini tidak pernah terlalu optimis.
     */
    private const MONO_ADVANCE = 0.62;

    /**
     * Nilai simbol lengkap: start, data, digit periksa, stop.
     *
     * @return list<int>
     */
    public static function encode(string $data): array
    {
        self::assertPrintable($data);

        $length = strlen($data);

        if ($length === 0) {
            throw new InvalidArgumentException('Tidak ada teks yang bisa dijadikan barcode.');
        }

        // START. Deret angka sepanjang 4 atau lebih di awal dikodekan set C —
        // aturan spesifikasi, dan yang membuat "1234567890" muat separuh lebar.
        $mode = self::digitRunAt($data, 0) >= 4 ? 'C' : 'B';
        $values = [$mode === 'C' ? self::START_C : self::START_B];

        $i = 0;

        while ($i < $length) {
            $run = self::digitRunAt($data, $i);

            if ($mode === 'C') {
                if ($run >= 2) {
                    $values[] = (int) substr($data, $i, 2);
                    $i += 2;

                    continue;
                }

                // Sisa deret ganjil, atau bukan angka: kembali ke set B.
                $values[] = self::CODE_B;
                $mode = 'B';

                continue;
            }

            // Set B. Beralih ke C hanya bila yang didapat cukup banyak untuk
            // membayar simbol peralihannya: enam angka di tengah teks, atau
            // empat yang menutup teksnya.
            if ($run >= 6 || ($run >= 4 && $i + $run === $length)) {
                $values[] = self::CODE_C;
                $mode = 'C';

                continue;
            }

            $values[] = ord($data[$i]) - 32;
            $i++;
        }

        $values[] = self::checksum($values);
        $values[] = self::STOP;

        return $values;
    }

    /**
     * DIGIT PERIKSA MOD-103 BERBOBOT.
     *
     * Jumlah = nilai START + (posisi × nilai) untuk tiap simbol data, dengan
     * posisi dimulai dari 1 pada simbol data PERTAMA — start sendiri berbobot
     * satu kali, bukan nol kali dan bukan posisi nol. Salah satu digit di sini
     * menghasilkan gambar yang tampak sempurna dan tidak terbaca apa pun.
     *
     * @param  list<int>  $values  start + data, tanpa digit periksa dan tanpa stop
     */
    public static function checksum(array $values): int
    {
        $sum = $values[0];

        foreach (array_slice($values, 1) as $index => $value) {
            $sum += ($index + 1) * $value;
        }

        return $sum % 103;
    }

    /**
     * BERAPA MODUL LEBAR simbol ini, zona tenang IKUT.
     *
     * Ini angka yang menentukan apakah sebuah kode muat di stikernya: lebar
     * modul cetak = lebar kotak ÷ angka ini. Pemanggil cetak memakainya untuk
     * MENOLAK kode yang tidak muat alih-alih mengecilkan gambarnya sampai di
     * bawah MIN_MODULE_MM — penyusutan diam-diam adalah bagaimana lembar F/LBL
     * dulu menghasilkan stiker yang tidak terbaca pemindai mana pun tanpa satu
     * pun kata di layar.
     */
    public static function moduleCount(string $data): int
    {
        $modules = 2 * self::QUIET_MODULES;

        foreach (self::encode($data) as $value) {
            $modules += array_sum(array_map('intval', str_split(self::PATTERNS[$value])));
        }

        return $modules;
    }

    /**
     * Label sebagai SVG mandiri.
     *
     * $options: module (satuan viewBox per modul), height (tinggi batang dalam
     * satuan viewBox), text (bool, teks terbaca-manusia), fontSize, label
     * (teks manusia yang DIBACA, bila berbeda dari yang dikodekan — dipakai
     * untuk menuliskan "ITM-0001" di bawah barcode pemasok), dan widthMm.
     *
     * `widthMm` ADALAH SATU-SATUNYA OPSI YANG MENENTUKAN UKURAN CETAK.
     * `module` hanya memilih satuan viewBox; begitu SVG-nya diletakkan di
     * dalam kotak yang lebih sempit daripada lebar alaminya, yang menentukan
     * lebar batang di kertas adalah kotaknya, bukan opsinya. Sebelum widthMm
     * ada, menaikkan `module` dari 2 ke 6 pada lembar F/LBL menghasilkan
     * barcode yang lebar batangnya PERSIS SAMA dan tingginya sepertiga —
     * sebuah kendali yang tidak mengendalikan apa pun. Dengan widthMm, atribut
     * width/height SVG ditulis dalam milimeter dan lebar modul cetaknya
     * terhitung: widthMm ÷ moduleCount().
     */
    public static function svg(string $data, array $options = []): string
    {
        $module = max(1, (int) ($options['module'] ?? self::DEFAULT_MODULE));
        $barHeight = max(10, (int) ($options['height'] ?? 56));
        $withText = ($options['text'] ?? true) !== false;
        $fontSize = max(6, (int) ($options['fontSize'] ?? 11));
        $human = (string) ($options['label'] ?? $data);

        $values = self::encode($data);

        $x = self::QUIET_MODULES * $module;
        $bars = [];
        $isBar = true;

        foreach ($values as $value) {
            foreach (str_split(self::PATTERNS[$value]) as $width) {
                $w = ((int) $width) * $module;

                if ($isBar) {
                    $bars[] = ['x' => $x, 'w' => $w];
                }

                $x += $w;
                $isBar = ! $isBar;
            }

            // Setiap simbol punya jumlah elemen GENAP (6) kecuali stop (7),
            // jadi simbol berikutnya selalu mulai dengan batang lagi. Menyetel
            // ulang di sini menjadikannya pernyataan, bukan kebetulan.
            $isBar = true;
        }

        $width = $x + self::QUIET_MODULES * $module;

        /*
         * TEKS MANUSIA DIPATAHKAN, TIDAK DIBIARKAN KELUAR VIEWPORT.
         *
         * `<text text-anchor="middle">` dipusatkan pada $width/2 tanpa satu pun
         * batas lebar, dan akar SVG memotong yang keluar — DI KEDUA UJUNG,
         * diam-diam. Barcode 13 digit di bawah kode item 40 karakter tercetak
         * sebagai 12 digit yang terlihat lengkap: orang gudang yang batangnya
         * tergores mengetik ulang `899100212345` untuk barang ber-barcode
         * `8991002123458`, sistem menjawab "tidak ada item dengan kode itu",
         * dan tidak ada apa pun di label yang memberi tahu bahwa yang dibacanya
         * sudah terpotong.
         *
         * Dipatahkan dan bukan dikecilkan fontnya: font yang menyusut sampai
         * muat berhenti bisa dibaca orang, dan yang dibutuhkan baris ini
         * justru dibaca orang.
         */
        $lines = $withText ? self::wrapLabel($human, $width, $fontSize) : [];
        $lineHeight = $fontSize + 3;
        $textHeight = $lines === [] ? 0 : count($lines) * $lineHeight + 3;
        $height = $barHeight + $textHeight;

        $rects = '';

        foreach ($bars as $bar) {
            $rects .= sprintf('<rect x="%d" y="0" width="%d" height="%d"/>', $bar['x'], $bar['w'], $barHeight);
        }

        $text = '';

        foreach ($lines as $index => $line) {
            $text .= sprintf(
                '<text x="%s" y="%d" text-anchor="middle" font-family="monospace" font-size="%d" fill="#000">%s</text>',
                $width / 2,
                $barHeight + $fontSize + 2 + $index * $lineHeight,
                $fontSize,
                htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        // Ukuran cetak dalam MILIMETER bila pemanggilnya menyebutnya; kalau
        // tidak, satuan viewBox seperti sebelumnya (SVG mandiri di luar lembar
        // cetak, mis. pratinjau layar).
        $widthMm = isset($options['widthMm']) ? (float) $options['widthMm'] : null;
        $size = $widthMm === null
            ? sprintf('width="%d" height="%d"', $width, $height)
            : sprintf('width="%smm" height="%smm"',
                self::mm($widthMm),
                self::mm($widthMm * $height / $width),
            );

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" %s viewBox="0 0 %d %d" role="img" aria-label="Barcode %s">'
            .'<rect x="0" y="0" width="%d" height="%d" fill="#fff"/><g fill="#000">%s</g>%s</svg>',
            $size, $width, $height,
            htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $width, $height,
            $rects,
            $text,
        );
    }

    /**
     * Teks manusia dipecah menjadi baris yang MUAT di dalam viewBox.
     *
     * @return list<string>
     */
    private static function wrapLabel(string $text, int $width, int $fontSize): array
    {
        $perLine = max(1, (int) floor($width / ($fontSize * self::MONO_ADVANCE)));
        $characters = mb_str_split($text);

        if (count($characters) <= $perLine) {
            return [$text];
        }

        return array_map(
            fn (array $chunk): string => implode('', $chunk),
            array_chunk($characters, $perLine),
        );
    }

    /** Milimeter tanpa nol ekor yang tak berarti — atribut SVG, bukan kalimat. */
    private static function mm(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    /**
     * Apakah teks ini bisa dijadikan Code 128 di sini — dipakai layar dan
     * formulir cetak untuk MENGATAKANNYA lebih dulu, alih-alih melempar.
     */
    public static function supports(string $data): bool
    {
        if ($data === '') {
            return false;
        }

        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $code = ord($data[$i]);

            if ($code < 32 || $code > 126) {
                return false;
            }
        }

        return true;
    }

    private static function assertPrintable(string $data): void
    {
        if (self::supports($data) || $data === '') {
            return;
        }

        // Menyebut karakternya, karena yang menemukannya adalah orang yang
        // sedang mencetak label dan tidak bisa melihat isi kolom barcode.
        throw new InvalidArgumentException(
            'Kode "'.$data.'" memuat karakter yang tidak bisa dijadikan barcode Code 128 di sini '
            .'(hanya huruf, angka, dan tanda baca ASCII biasa). Perbaiki kode atau barcode item ini lebih dulu.'
        );
    }

    private static function digitRunAt(string $data, int $offset): int
    {
        $run = 0;
        $length = strlen($data);

        while ($offset + $run < $length && $data[$offset + $run] >= '0' && $data[$offset + $run] <= '9') {
            $run++;
        }

        return $run;
    }
}
