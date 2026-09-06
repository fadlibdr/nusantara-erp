<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * App launcher, aturan landing, dan preferensi server — kabelnya (P1-C).
 *
 * Empat berkas tanpa build step di antaranya: schema.js mengatakan "Beranda"
 * ada di menu, app.js memutuskan `#/home` menjadi sesuatu, home.js
 * menggambarnya, prefs.js yang menyimpan favorit/kepadatan. Tidak ada yang
 * gagal dengan berisik ketika salah satunya bergeser — persis alasan
 * NavRouteRegistryTest ada, dan disiplin yang sama (grep atas berkas yang akan
 * dibaca seorang peninjau) dipakai di sini.
 *
 * Yang dijaga, dan kegagalan yang dicegahnya:
 *  - landing ponsel. Tanpa aturan 760 px, orang di ponsel mendarat di dasbor
 *    yang bagi tiga dari 12 peran demo KOSONG, dengan menu tersembunyi di laci.
 *  - tautan-dalam. Aturan landing yang membaca currentPath() alih-alih
 *    location.hash akan membajak setiap notifikasi yang menaut ke dokumen:
 *    currentPath() mengarang 'dashboard' saat hash kosong, jadi keduanya tampak
 *    sama dan hanya yang satu benar.
 *  - kejujuran ubin. `count ?? 0` di satu tempat mengubah "tidak tahu" menjadi
 *    kabar baik palsu.
 *  - migrasi preferensi. app.js yang masih membaca kunci localStorage P1-B
 *    sendiri berarti dua sumber kebenaran untuk favorit yang sama.
 */
class LauncherWiringTest extends ErpTestCase
{
    public function test_the_launcher_screen_is_registered_and_reachable_from_the_menu(): void
    {
        $app = $this->file('app/js/app.js');

        $this->assertFileExists(public_path('app/js/views/home.js'));
        $this->assertStringContainsString('export async function renderHome(', $this->file('app/js/views/home.js'));
        $this->assertMatchesRegularExpression("/import \{[^}]*\brenderHome\b[^}]*\} from '\.\/views\/home\.js'/", $app);
        $this->assertStringContainsString("route('home'", $app,
            "app.js has no route('home', ...) — the NAV entry and the header house button both land on the not-found fallback.");

        // Baris menu + tombol rumah di header: dua jalan, dan keduanya disebut
        // keputusan pemilik #3 ("sidebar/header gets a 'Beranda' entry").
        $this->assertStringContainsString("{ label: 'Beranda', route: 'home' }", $this->file('app/js/schema.js'));
        $this->assertMatchesRegularExpression("/iconName: 'home'[^)]*title: 'Beranda'/", $app);
        $this->assertArrayHasKey('home', $this->iconPaths(), 'ui.js icon() tidak punya glyph "home"; tombolnya menggambar path kosong.');
    }

    public function test_the_landing_rule_uses_the_760px_breakpoint_and_only_when_there_is_no_hash(): void
    {
        $app = $this->file('app/js/app.js');
        $rule = $this->landingRule($app);

        $this->assertNotNull($rule, 'landOnDefault() tidak ditemukan di app.js — aturan landing (keputusan pemilik #3) hilang.');
        $this->assertStringContainsString("'home'", $rule);
        $this->assertStringContainsString("'dashboard'", $rule);

        /*
         * `>`, bukan `>=`. `@media (max-width: 760px)` inklusif: pada 760 px
         * tepat sidebar SUDAH menjadi laci. Aturan landing yang juga inklusif
         * memberi lebar itu laci DAN dasbor — gabungan yang aturan ini ada
         * untuk mencegah (terukur 6 Sep 2026: 760 px → #/dashboard dengan nav
         * di luar layar). Kedua sisi diuji di sini karena keduanya berada di
         * berkas berbeda dan tidak ada yang gagal berisik saat salah satunya
         * bergeser.
         */
        $this->assertStringContainsString('window.innerWidth > 760', $rule);
        $this->assertStringNotContainsString('>= 760', $rule,
            'Aturan landing inklusif pada 760 px, sama seperti @media (max-width: 760px): lebar itu mendapat laci DAN dasbor.');
        $this->assertStringContainsString('@media (max-width: 760px)', $this->file('app/app.css'),
            'app.css tidak lagi melipat sidebar pada 760 px; aturan landing memakai angka yang tidak berarti apa-apa lagi.');

        // location.hash, BUKAN currentPath(): yang kedua mengarang 'dashboard'
        // saat hash kosong, jadi setiap tautan-dalam akan dibajak ke launcher.
        $this->assertStringContainsString('location.hash', $rule);
        $this->assertStringNotContainsString('currentPath()', $rule);

        // Dipanggil dari boot(), bukan hanya didefinisikan.
        $this->assertStringContainsString('landOnDefault();', $app);
    }

    /**
     * Panduan onboarding membuka langkah 1 pada HALAMAN PEMBUKA, dan sejak P1-C
     * halaman pembuka ponsel bukan dasbor melainkan launcher. tour() yang tetap
     * menyebut 'dashboard' di kedua lebar membuat visit() menganggap orangnya
     * pindah pada langkah pertama: ia menavigasi keluar dari launcher DAN
     * melipat lembar bawahnya, jadi panduan lahir terlipat pada setiap masuk
     * pertama di ponsel (terukur 6 Sep 2026: S19 merah, dock state=collapsed
     * h=49 px, jejak hash ['#/home','#/dashboard']).
     */
    public function test_the_onboarding_tour_opens_on_the_page_the_landing_rule_chose(): void
    {
        $onboarding = $this->file('app/js/views/onboarding.js');
        $tour = $this->functionBody($onboarding, 'function tour()');

        $this->assertNotNull($tour, 'tour() tidak ditemukan di views/onboarding.js.');
        $this->assertStringContainsString('MOBILE.matches', $tour,
            'tour() tidak menanyakan lebar layar, jadi langkah 1 di ponsel memindah orangnya keluar dari launcher dan melipat lembar panduannya.');
        $this->assertStringContainsString("route: 'home'", $tour);
        $this->assertStringContainsString("route: 'dashboard'", $tour);

        // …dan lebar yang ditanyakannya adalah titik potong yang sama dengan
        // aturan landing: dua angka yang berselisih di sini berarti satu lebar
        // layar yang mendarat di launcher tetapi dituntun ke dasbor.
        $this->assertStringContainsString("matchMedia('(max-width: 760px)')", $onboarding);
    }

    public function test_the_tiles_never_turn_an_unknown_count_into_zero(): void
    {
        $home = $this->file('app/js/views/home.js');

        $this->assertStringContainsString("'—'", $home, 'home.js tidak pernah menulis "—"; ubin tanpa angka pasti menulis sesuatu yang lain.');
        foreach (['count || 0', 'count ?? 0', 'count) || 0'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $home,
                "home.js memakai \"{$forbidden}\": hitungan yang tidak diketahui menjadi 0, dan 0 adalah pernyataan.");
        }
    }

    public function test_the_module_home_carries_counts_recents_and_a_star_beside_each_card(): void
    {
        $module = $this->file('app/js/views/module.js');

        // Bintang BERSEBELAHAN dengan kartu, tidak bersarang di dalamnya:
        // <button> di dalam <a> bukan HTML yang sah, dan Enter di atasnya
        // membuka layarnya alih-alih memasang bintang.
        $this->assertStringContainsString("el('li.module-cell', [screenCard(item), starButton(item)])", $module);
        $this->assertStringContainsString('prefs.toggleFavorite(', $module);

        // Kosong yang jujur, bukan seksi yang hilang.
        $this->assertStringContainsString('Belum ada yang dibuka.', $module);
        $this->assertStringContainsString("'—'", $module);

        /*
         * SATU permintaan untuk angka utama DAN angka sekunder: Proyek/Keuangan
         * memakai dashboard/summary?include=modules (yang membawa keduanya),
         * modul lain core/modules saja. Endpoint ketiga di sini berarti beranda
         * modul menambah beban yang paket ini justru berjanji tidak menambah.
         */
        preg_match_all("/api\.get\('([^']+)'/", $module, $calls);
        $this->assertSame(['core/dashboard/summary', 'core/modules'], array_values(array_unique($calls[1])));
        $this->assertStringContainsString("include: 'modules'", $module);
    }

    public function test_personal_preferences_are_read_through_prefs_js_only(): void
    {
        $this->assertFileExists(public_path('app/js/prefs.js'));
        $prefs = $this->file('app/js/prefs.js');
        $app = $this->file('app/js/app.js');

        $this->assertMatchesRegularExpression("/import \{[^}]*\bprefs\b[^}]*\} from '\.\/prefs\.js'/", $app);
        $this->assertStringContainsString('core/me/preferences', $prefs);

        /*
         * Kunci localStorage P1-B hanya boleh disebut di prefs.js, dan di sana
         * hanya untuk DINAIKKAN sekali lalu dihapus. app.js yang masih
         * membacanya berarti dua sumber kebenaran untuk favorit yang sama —
         * dan yang di localStorage akan menang di peramban yang pernah
         * memakainya, diam-diam.
         */
        foreach (['nusantara_erp_fav', 'nusantara_erp_recent', 'nusantara_erp_density'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $app,
                "app.js masih menyebut kunci localStorage \"{$legacy}\"; preferensi itu milik server sejak P1-C.");
            $this->assertStringContainsString($legacy, $prefs,
                "prefs.js tidak menyebut \"{$legacy}\", jadi preferensi yang sudah ada di peramban orang tidak pernah dinaikkan.");
        }

        $this->assertStringContainsString('removeItem(personalKey(base))', $prefs,
            'Kunci warisan tidak pernah dihapus; migrasinya akan berjalan setiap boot selamanya.');
    }

    /** Separuh penolakan: pembacanya harus bisa bilang tidak. */
    public function test_the_readers_can_still_say_no(): void
    {
        $this->assertNull($this->landingRule("function bukanLandOnDefault() {\n  return 1;\n}\n"));
        $this->assertNull($this->functionBody("const tour = 1;\n", 'function tour()'));
        $this->assertArrayNotHasKey('rumah', $this->iconPaths());
        $this->assertArrayHasKey('star', $this->iconPaths());
    }

    /** Badan fungsi landOnDefault(), atau null bila tidak ada. */
    private function landingRule(string $source): ?string
    {
        return $this->functionBody($source, 'function landOnDefault()');
    }

    /**
     * Badan sebuah fungsi bertingkat-atas — dari tanda tangannya sampai kurung
     * tutup di kolom 0 (atau, untuk fungsi bersarang seperti tour(), kurung
     * tutup pada indentasinya sendiri). null bila tanda tangannya tidak ada.
     */
    private function functionBody(string $source, string $signature): ?string
    {
        $start = strpos($source, $signature);
        if ($start === false) {
            return null;
        }
        $indent = str_repeat(' ', $start - (int) strrpos(substr($source, 0, $start), "\n") - 1);
        $end = strpos($source, "\n{$indent}}", $start);

        return $end === false ? null : substr($source, $start, $end - $start);
    }

    /** @return array<string, true> nama glyph di PATHS ui.js */
    private function iconPaths(): array
    {
        $source = $this->file('app/js/ui.js');
        $start = strpos($source, 'const PATHS = {');
        $block = $start === false ? '' : substr($source, $start, (int) strpos($source, "\n};", $start) - $start);
        preg_match_all("/^  ([a-zA-Z]+): '/m", $block, $matches);

        return array_fill_keys($matches[1], true);
    }

    private function file(string $relative): string
    {
        return (string) file_get_contents(public_path($relative));
    }
}
