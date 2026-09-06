<?php

namespace Tests\Feature\Core;

use Modules\Core\Support\SpaNav;
use Modules\Core\Support\SpaWidgets;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Tests\ErpTestCase;

/**
 * Katalog widget dasbor konsisten dengan aplikasi di sekelilingnya (P1-D).
 *
 * Katalog di `views/widgets/registry.js` menjanjikan empat hal per widget, dan
 * ketiganya yang pertama hanya bisa salah DIAM-DIAM: izin yang tidak pernah
 * dicetak PermissionSeeder membuat widget hilang dari layar semua orang tanpa
 * satu galat pun; rute yang tidak terdaftar membuat kaki kartunya mendarat di
 * "Halaman tidak ditemukan"; prefix modul yang bukan grup NAV membuat aksennya
 * jatuh ke netral dan pengelompokan lacinya berantakan. Tidak satu pun dari
 * ketiganya menjatuhkan uji lain, dan tidak satu pun terlihat di layar orang
 * yang tidak memasang widget itu.
 *
 * Pemindaian berkas, bukan salinan tangan — alasan SpaNav, dipakai ulang.
 */
class DashboardWidgetRegistryTest extends ErpTestCase
{
    private function registry(): string
    {
        return (string) file_get_contents(public_path('app/js/views/widgets/registry.js'));
    }

    /** @return list<array{id: string, block: string}> satu entri katalog per elemen. */
    private function entries(): array
    {
        $source = $this->registry();
        $out = [];

        foreach (SpaWidgets::ids() as $id) {
            $start = strpos($source, "    id: '{$id}',");
            $this->assertNotFalse($start, "Entri katalog [{$id}] tidak ditemukan lagi di registry.js.");
            $end = strpos($source, "\n  },", $start);
            $out[] = ['id' => $id, 'block' => substr($source, $start, ($end === false ? strlen($source) : $end) - $start)];
        }

        return $out;
    }

    public function test_the_catalogue_is_read_at_all(): void
    {
        // Penjaga anti-no-op: regex yang berhenti cocok akan membuat setiap uji
        // di bawah lolos atas daftar kosong.
        $this->assertGreaterThanOrEqual(18, count(SpaWidgets::ids()),
            'SpaWidgets membaca terlalu sedikit widget — polanya tidak lagi cocok dengan registry.js.');
        $this->assertSame(SpaWidgets::ids(), array_unique(SpaWidgets::ids()), 'Ada id widget yang kembar.');

        foreach (SpaWidgets::ids() as $id) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/', $id,
                "Id widget [{$id}] bukan kebab-case; ia juga nama berkasnya.");
        }
    }

    /**
     * Setiap izin yang disebut katalog benar-benar dicetak PermissionSeeder.
     *
     * Salah ketik di sini tidak menjatuhkan apa pun: session.can('fin.veiw')
     * menjawab false untuk SEMUA orang, jadi widget-nya hilang dari laci dan
     * dari setiap dasbor — termasuk milik admin — tanpa galat, tanpa log.
     */
    public function test_every_permission_named_by_a_widget_exists(): void
    {
        $known = PermissionSeeder::expected();

        foreach (SpaWidgets::ids() as $id) {
            foreach (SpaWidgets::permissionsOf($id) as $permission) {
                if ($permission === '*.approve') {
                    // Predikat, bukan nama izin: cermin ANY_APPROVE di schema.js
                    // (pemegang `<prefix>.approve` mana pun).
                    continue;
                }

                $this->assertContains($permission, $known,
                    "Widget [{$id}] menuntut izin [{$permission}] yang tidak pernah dicetak PermissionSeeder — "
                    .'widget itu tidak akan pernah tampil untuk siapa pun.');
            }
        }
    }

    /**
     * Rute kaki kartu setiap widget benar-benar ada: baris NAV (`r/...`) atau
     * rute yang didaftarkan app.js.
     */
    public function test_every_widget_points_at_a_route_that_exists(): void
    {
        $app = (string) file_get_contents(public_path('app/js/app.js'));
        preg_match_all("/\broute\('([^']+)'/", $app, $matches);
        $routes = array_merge($matches[1], SpaNav::routes());

        $this->assertContains('dashboard', $routes, 'Daftar rute tidak terbaca — uji ini tidak membuktikan apa pun.');

        foreach ($this->entries() as $entry) {
            $this->assertSame(1, preg_match("/route: '([^']+)'/", $entry['block'], $found),
                "Entri katalog [{$entry['id']}] tidak menyebut route.");

            $this->assertContains($found[1], $routes,
                "Widget [{$entry['id']}] menaut ke rute [{$found[1]}] yang tidak didaftarkan app.js maupun NAV — "
                .'kaki kartunya mendarat di "Halaman tidak ditemukan".');
        }
    }

    /**
     * `route` katalog bukan metadata mati: setiap widget PUNYA kaki kartu, dan
     * kaki itu menuju rute yang katalognya sebut.
     *
     * Verifikasi kedua P1-D: tidak satu baris JavaScript pun membaca
     * `widget.route` — setiap kaki menuliskan sasarannya sendiri — sehingga 19
     * string yang dipaku uji di atas bukan string yang dikapalkan. Sekaligus
     * dua widget tidak menggambar kaki sama sekali (kalender, ringkasan-uang)
     * walau katalog memberi keduanya route, dan tiga lagi menjatuhkan kakinya
     * justru pada keadaan KOSONG — layar yang paling perlu diperiksa.
     *
     * Yang dijaga di sini karena itu dua hal yang bisa dijaga tanpa runtime JS:
     *  1. setiap berkas widget memanggil footLink();
     *  2. sedikitnya satu `navigate('…')` di berkas itu MULAI DENGAN route
     *     katalognya — jadi `reports?tab=ar-aging` sah untuk route `reports`
     *     (tautan dalam ke tab layar yang sama), sementara route yang tidak
     *     pernah disentuh berkasnya jatuh.
     */
    public function test_every_widget_draws_a_foot_that_goes_where_the_catalogue_says(): void
    {
        $checked = 0;

        foreach ($this->entries() as $entry) {
            $this->assertSame(1, preg_match("/route: '([^']+)'/", $entry['block'], $found),
                "Entri katalog [{$entry['id']}] tidak menyebut route.");
            $route = $found[1];

            $path = public_path('app/js/views/widgets/'.$entry['id'].'.js');
            $this->assertFileExists($path);
            $source = (string) file_get_contents($path);

            $this->assertStringContainsString('footLink(', $source, sprintf(
                'Widget [%s] tidak menggambar kaki kartu satu kali pun, jadi katalognya menjanjikan pintu '
                .'("route: %s") yang tidak ada di layar mana pun.',
                $entry['id'], $route,
            ));

            preg_match_all("/navigate\('([^']+)'/", $source, $targets);
            $this->assertNotSame([], $targets[1], "Widget [{$entry['id']}] tidak menaut ke mana pun.");

            $hits = array_filter(
                $targets[1],
                static fn (string $target): bool => $target === $route || str_starts_with($target, $route.'?'),
            );

            $this->assertNotSame([], $hits, sprintf(
                'Widget [%s] menaut ke [%s] sementara katalognya menyebut route [%s]. Salah satunya salah, dan '
                .'yang dipaku uji adalah katalognya — jadi selama ini 19 string route dipaku tanpa satu pun '
                .'benar-benar dikapalkan.',
                $entry['id'], implode(', ', array_unique($targets[1])), $route,
            ));

            $checked++;
        }

        $this->assertSame(19, $checked, 'Jumlah widget yang disapu berubah — sapuan ini kehilangan sasarannya.');
    }

    /** Prefix modul setiap widget adalah grup NAV sungguhan (aksen + pengelompokan). */
    public function test_every_widget_names_a_real_module_prefix(): void
    {
        $prefixes = SpaNav::prefixes();
        $this->assertNotSame([], $prefixes, 'Prefix NAV tidak terbaca — uji ini tidak membuktikan apa pun.');

        foreach ($this->entries() as $entry) {
            $this->assertSame(1, preg_match("/module: '([^']+)'/", $entry['block'], $found),
                "Entri katalog [{$entry['id']}] tidak menyebut module.");
            $this->assertContains($found[1], $prefixes,
                "Widget [{$entry['id']}] mengaku milik modul [{$found[1]}] yang bukan grup NAV.");
        }
    }

    /**
     * Ukuran yang ditawarkan setiap widget adalah ukuran yang dikenal, dan
     * ukuran BAWAANNYA salah satu di antaranya.
     *
     * Bawaan di luar `sizes` bukan galat yang terlihat: resolveLayout diam-diam
     * menggantinya dengan `widget.size` — yang justru nilai yang salah itu —
     * sehingga kartunya digambar dengan lebar yang katalognya sendiri sebut
     * tidak masuk akal.
     */
    public function test_sizes_are_known_and_the_default_is_one_of_them(): void
    {
        foreach ($this->entries() as $entry) {
            $this->assertSame(1, preg_match("/sizes: \[([^\]]*)\]/", $entry['block'], $sizesMatch));
            $this->assertSame(1, preg_match("/size: '([^']+)',/", $entry['block'], $defaultMatch));

            preg_match_all("/'([a-z]+)'/", $sizesMatch[1], $sizes);
            $sizes = $sizes[1];

            $this->assertNotSame([], $sizes, "Widget [{$entry['id']}] tidak menawarkan satu ukuran pun.");
            foreach ($sizes as $size) {
                $this->assertContains($size, SpaWidgets::SIZES,
                    "Widget [{$entry['id']}] menawarkan ukuran [{$size}] yang tidak dikenal katalog.");
            }

            $this->assertContains($defaultMatch[1], $sizes,
                "Ukuran bawaan widget [{$entry['id']}] tidak ada di antara ukuran yang ditawarkannya.");
        }
    }

    /**
     * Keranjang umur di widget SAMA dengan keranjang umur di layar Laporan.
     *
     * Dua layar yang menyebut "31-60 hari" dengan warna berbeda — atau yang
     * satu memakai `31_60` dan yang lain `31-60` — membuat pembacanya mengira
     * ia sedang melihat dua ukuran yang berbeda. Salinannya disengaja
     * (views/widgets/aging.js), jadi penjaganya harus uji.
     */
    public function test_the_aging_buckets_mirror_the_reports_screen(): void
    {
        $reports = (string) file_get_contents(public_path('app/js/views/reports.js'));
        $widget = (string) file_get_contents(public_path('app/js/views/widgets/aging.js'));

        foreach (['current', '1_30', '31_60', '61_90', 'over_90'] as $key) {
            $this->assertStringContainsString($key, $reports, "Kunci keranjang [{$key}] hilang dari reports.js.");
            $this->assertStringContainsString("key: '{$key}'", $widget,
                "Kunci keranjang [{$key}] ada di layar Laporan tetapi tidak di widget dasbor.");
        }

        // Nada merah/kuning ikut disalin: keranjang > 90 hari yang netral di
        // dasbor dan merah di laporan adalah dua penilaian atas satu angka.
        $this->assertStringContainsString("key: 'over_90', label: '> 90 hari', tone: 'red'", $widget);
        $this->assertStringContainsString("key: '31_60', label: '31-60 hari', tone: 'amber'", $widget);
    }

    /**
     * Ukuran batch pemuatan ditulis SATU kali, dan angkanya 4.
     *
     * ROADMAP-HASHMICRO menuliskan "muat per batch 4" sebagai isi paket ini;
     * angka itu yang menjaga jumlah permintaan serentak tidak bergantung pada
     * berapa banyak widget yang dipasang orangnya.
     */
    public function test_widgets_load_four_at_a_time(): void
    {
        $dashboard = (string) file_get_contents(public_path('app/js/views/dashboard.js'));

        $this->assertSame(1, preg_match('/^const BATCH = (\d+);$/m', $dashboard, $found),
            'Ukuran batch tidak lagi ditulis sebagai satu konstanta di dashboard.js.');
        $this->assertSame('4', $found[1], 'Batch pemuatan widget bukan 4.');
        $this->assertStringContainsString('start += BATCH', $dashboard,
            'Konstanta BATCH ada tetapi bukan yang memotong perulangan pemuatan.');
    }
}
