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
        $this->assertStringContainsString('window.innerWidth >= 760', $rule);
        $this->assertStringContainsString("'home'", $rule);
        $this->assertStringContainsString("'dashboard'", $rule);

        // location.hash, BUKAN currentPath(): yang kedua mengarang 'dashboard'
        // saat hash kosong, jadi setiap tautan-dalam akan dibajak ke launcher.
        $this->assertStringContainsString('location.hash', $rule);
        $this->assertStringNotContainsString('currentPath()', $rule);

        // Dipanggil dari boot(), bukan hanya didefinisikan.
        $this->assertStringContainsString('landOnDefault();', $app);
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
        $this->assertArrayNotHasKey('rumah', $this->iconPaths());
        $this->assertArrayHasKey('star', $this->iconPaths());
    }

    /** Badan fungsi landOnDefault(), atau null bila tidak ada. */
    private function landingRule(string $source): ?string
    {
        $start = strpos($source, 'function landOnDefault()');
        if ($start === false) {
            return null;
        }
        $end = strpos($source, "\n}", $start);

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
