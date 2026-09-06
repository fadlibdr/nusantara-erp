<?php

namespace Tests\Feature\Core;

use Modules\Core\Support\SpaWidgets;
use Tests\ErpTestCase;

/**
 * Setiap widget dasbor harus BERCABANG pada kegagalan sumbernya (P1-D).
 *
 * Aturan yang dijaga berkas ini lebih tua daripada widget-nya. Sampai Temuan 79
 * dasbor mengambil sumber-sumbernya dengan `safe()` yang berakhir
 * `.catch(() => [])`, jadi sumber yang GAGAL dan sumber yang memang KOSONG
 * menghasilkan nilai yang persis sama — dan setiap ubin yang digambar hanya
 * bila `.length` benar berhenti ada.
 *
 * Itu bukan hipotesis. Saat SQLite berebut kunci, kartu Kalender dan ubin
 * "Termin siap ditagih" hilang bergantian sementara sisa dasbor tampak sehat —
 * dan "Termin siap ditagih" adalah ubin yang membawa Rp 14,55 miliar pekerjaan
 * yang sudah berhak ditagih dan belum ditagih. Pembaca yang tidak melihat
 * ubinnya menyimpulkan tidak ada yang bisa ditagih.
 *
 * YANG BERUBAH DI P1-D: dasbor tidak lagi satu berkas. `views/dashboard.js`
 * hanya menyusun; setiap angka hidup di `views/widgets/<id>.js`, dan susunannya
 * dipilih pemakainya sendiri — sehingga sebuah widget yang menyimpang tidak
 * lagi tampak di layar siapa pun kecuali orang yang kebetulan memasangnya.
 * Karena itu pemindaian di sini berpindah dari SATU berkas ke SELURUH folder,
 * dan tumbuh sendiri: berkas widget berikutnya ikut diperiksa tanpa satu baris
 * pun ditambahkan di sini.
 *
 * SIAPA YANG DIANGGAP WIDGET: berkas di `views/widgets/` yang mengekspor
 * `build(`. Itu definisi yang sama dengan yang dipakai penyusunnya (dashboard.js
 * memanggil `module.build(ctx)`), jadi sebuah widget tidak bisa lolos
 * pemeriksaan dengan cara tidak mendaftar — mendaftar bukan syaratnya, punya
 * build() adalah. Berkas pembantu (kit.js, registry.js, aging.js) tidak punya
 * build() dan tidak diperiksa; yang menjaga mereka adalah widget yang
 * memakainya.
 *
 * Pemeriksaannya grep, dengan alasan yang sama seperti NavRouteRegistryTest:
 * tidak ada runtime JS di host ini, dan grep atas berkas yang dibaca peninjau
 * tidak bisa basi seperti daftar yang dipelihara tangan.
 */
class DashboardTileFailureTest extends ErpTestCase
{
    /** Tidak ada catch di dasbor yang boleh membuang error yang diterimanya. */
    public function test_no_dashboard_file_turns_a_failed_fetch_into_a_silent_empty_value(): void
    {
        $files = $this->dashboardFiles();
        $this->assertGreaterThan(15, count($files), 'Folder widget nyaris kosong — pemindaian ini kehilangan sasarannya.');

        $catches = 0;

        foreach ($files as $path => $source) {
            // Kode saja: docblock kit.js MENGUTIP `.catch(() => [])` sebagai bug
            // yang diperbaikinya, dan uji yang tidak bisa membedakan prosa dari
            // kode akan merah justru pada komentar yang menjelaskannya.
            $code = $this->codeOnly($source);
            $catches += preg_match_all('/\.catch\(/', $code);

            $this->assertFalse(
                $this->swallowsFailures($code),
                sprintf(
                    '%s memuat handler berbentuk `.catch(() => [])`. Catch yang tidak MENERIMA error-nya tidak bisa '
                    .'memberi tahu ubinnya bahwa angkanya tidak diketahui, sehingga ubin itu diam-diam hilang atau '
                    .'menulis Rp 0 — persis kegagalan yang membuat kartu Kalender dan "Termin siap ditagih" lenyap '
                    .'saat SQLite berebut kunci. Terima error-nya, catat, dan tandai nilainya seperti safe().',
                    $path,
                ),
            );
        }

        // Regex yang diam-diam berhenti cocok akan membuat uji ini no-op yang
        // tetap melaporkan PASS.
        $this->assertGreaterThan(0, $catches, 'Tidak ada satu pun `.catch(` di berkas dasbor. Berkasnya pindah, atau pemindaian ini sudah tidak membacanya.');
    }

    /**
     * Bagian yang menolak: buktikan detektornya berkata YA pada bentuk yang
     * pernah dirilis. Tanpa ini, uji di atas lolos untuk masukan apa pun.
     */
    public function test_a_catch_that_discards_its_error_is_reported(): void
    {
        $this->assertTrue($this->swallowsFailures(
            'const safe = (path, params) => api.get(path, params).then((rows) => rows || []).catch(() => []);',
        ));
        $this->assertTrue($this->swallowsFailures("api.list('core/calendar').catch(() => null),"));
        $this->assertTrue($this->swallowsFailures('load().catch( ( ) => { } )'));

        // …sementara catch yang menerima error-nya DITERIMA, jadi detektornya
        // bukan sekadar menolak setiap catch yang terlihat.
        $this->assertFalse($this->swallowsFailures(
            ".catch((error) => { console.error('x', error); return Object.assign([], { loadFailure: error }); })",
        ));

        // Dan prosa yang menyebut bentuk terlarang tetap prosa. Tanpa codeOnly()
        // uji ini tidak akan pernah bisa dibuat hijau: komentar di kit.js yang
        // menjelaskan penelanan akan terbaca sebagai penelanan itu sendiri.
        $this->assertFalse($this->swallowsFailures($this->codeOnly('/* dulu `.catch(() => [])`, sekarang tidak lagi */')));
        $this->assertFalse($this->swallowsFailures($this->codeOnly("// jangan pernah menulis .catch(() => null) di sini\n")));
    }

    /**
     * Perkakas bersamanya masih MENANDAI kegagalan. Tanpa `loadFailure`,
     * failure() tidak pernah benar dan setiap cabang di bawahnya kode mati —
     * 19 widget yang tampak berhati-hati dan tidak satu pun yang bekerja.
     */
    public function test_the_shared_kit_still_tags_a_failed_fetch(): void
    {
        $kit = $this->codeOnly($this->read('kit.js'));

        $this->assertStringContainsString('loadFailure', $kit);
        $this->assertMatchesRegularExpression('/export const failure =/', $kit,
            'kit.js tidak lagi mengekspor failure(); widget di bawahnya tidak punya apa pun untuk dicabangkan.');
    }

    /** Setiap widget bercabang pada kegagalan sumbernya, di KODE. */
    public function test_every_widget_branches_on_failure(): void
    {
        $widgets = $this->widgetFiles();
        $this->assertGreaterThan(15, count($widgets), 'Widget yang ditemukan terlalu sedikit — pemindaian ini kehilangan sasarannya.');

        foreach ($widgets as $id => $source) {
            $this->assertTrue(
                $this->branchesOnFailure($source),
                sprintf(
                    'Widget [%s] tidak pernah memanggil failure(), jadi fetch yang gagal tidak bisa dibedakan dari '
                    .'hasil kosong: kartunya menghilang atau melaporkan nol. Tambahkan cabang failure(...) yang '
                    .'menggambar failedStat()/failedBody() di samping cabang normalnya.',
                    $id,
                ),
            );
        }
    }

    /**
     * Bagian yang menolak dari pemeriksaan cabang: sebuah berkas yang TIDAK
     * bercabang harus dilaporkan, atau perulangan di atas lolos untuk daftar
     * berkas apa pun.
     */
    public function test_a_widget_with_no_failure_branch_is_reported(): void
    {
        $this->assertFalse($this->branchesOnFailure('export async function build() { return el("div"); }'));

        // Cabang yang hanya ada di KOMENTAR tidak menjaga apa pun.
        $this->assertFalse($this->branchesOnFailure("/* nanti: if (failure(rows)) ... */\nexport async function build() {}"));

        // …dan salah satu widget sungguhan tetap lolos, jadi detektornya bukan
        // "menolak segalanya".
        $this->assertTrue($this->branchesOnFailure($this->read('siap-tagih.js')));
    }

    /**
     * Setiap berkas ber-build() TERDAFTAR di katalog, dan setiap entri katalog
     * punya berkasnya.
     *
     * Kedua arahnya penting dan gagalnya berbeda. Berkas tanpa entri = kode
     * mati yang tetap ikut deploy dan tetap harus dipelihara. Entri tanpa
     * berkas = kartu yang ditawarkan laci "Atur dasbor", dipilih orangnya, lalu
     * gagal di-import — satu-satunya jalur di dasbor ini yang kegagalannya
     * tidak bisa dilaporkan widget-nya sendiri, karena kodenya tidak pernah
     * jalan.
     */
    public function test_the_catalogue_and_the_widget_files_are_the_same_set(): void
    {
        $files = array_keys($this->widgetFiles());
        $catalogue = SpaWidgets::ids();

        sort($files);
        sort($catalogue);

        $this->assertNotSame([], $catalogue, 'SpaWidgets tidak membaca satu id pun dari registry.js.');
        $this->assertSame($catalogue, $files,
            'Katalog dasbor dan berkas widget berbeda isi. Berkas tanpa entri adalah kode mati; entri tanpa berkas '
            .'adalah kartu yang ditawarkan laci lalu gagal dimuat.');
    }

    /* ------------------------------------------------------------ perkakas */

    /** True bila sebuah catch membuang error yang diberikan kepadanya. */
    private function swallowsFailures(string $source): bool
    {
        // `() =>` tanpa apa pun di antara kurungnya: handler-nya bahkan tidak
        // diberi sebabnya, jadi tidak ada yang di bawahnya bisa diberi tahu.
        return preg_match('/\.catch\(\s*\(\s*\)\s*=>/', $source) === 1;
    }

    private function branchesOnFailure(string $source): bool
    {
        return str_contains($this->codeOnly($source), 'failure(');
    }

    /** @return array<string, string> path relatif => sumber; dashboard.js + folder widget. */
    private function dashboardFiles(): array
    {
        $files = ['views/dashboard.js' => (string) file_get_contents(public_path('app/js/views/dashboard.js'))];

        foreach ($this->widgetPaths() as $path) {
            $files['views/widgets/'.basename($path)] = (string) file_get_contents($path);
        }

        return $files;
    }

    /** @return array<string, string> id widget => sumber (hanya berkas ber-build()). */
    private function widgetFiles(): array
    {
        $out = [];

        foreach ($this->widgetPaths() as $path) {
            $source = (string) file_get_contents($path);
            // Definisi yang sama dengan yang dipakai penyusunnya: dashboard.js
            // memanggil module.build(ctx).
            if (preg_match('/export\s+async\s+function\s+build\s*\(/', $this->codeOnly($source)) === 1) {
                $out[basename($path, '.js')] = $source;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function widgetPaths(): array
    {
        $paths = glob(public_path('app/js/views/widgets/*.js'));
        sort($paths);

        return $paths === false ? [] : $paths;
    }

    private function read(string $name): string
    {
        return (string) file_get_contents(public_path('app/js/views/widgets/'.$name));
    }

    /**
     * Buang komentar dan isi string supaya pemindaian membaca KODE. Satu lintasan
     * atas kedua bentuk komentar dan ketiga karakter kutip, bentuk yang sama
     * dengan pemeriksa keseimbangan, sehingga `//` di dalam string tidak pernah
     * membuka komentar dan kutip di dalam komentar tidak pernah membuka string.
     *
     * Berkas widget tidak memuat literal ekspresi reguler (satu-satunya
     * konstruksi yang pemindai sederhana ini tidak bisa bedakan dari pembagian);
     * bila kelak ada, penjaga assertGreaterThan di atas menyala lebih dulu,
     * karena pemindaian yang keluar sinkron merusak `.catch(` yang dihitungnya.
     */
    private function codeOnly(string $source): string
    {
        $out = '';
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            $next = $i + 1 < $length ? $source[$i + 1] : '';

            if ($char === '/' && $next === '/') {
                while ($i < $length && $source[$i] !== "\n") {
                    $i++;
                }
                $out .= "\n";

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($source, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                $out .= ' ';

                continue;
            }

            if ($char === '"' || $char === "'" || $char === '`') {
                $i++;
                while ($i < $length && $source[$i] !== $char) {
                    if ($source[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                $out .= "''";

                continue;
            }

            $out .= $char;
        }

        return $out;
    }
}
