<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * A NAV entry and the router that serves it live in two files with no build step
 * between them: schema.js says a menu item exists, app.js decides whether the
 * hash it points at resolves to anything. Nothing fails loudly when they drift.
 *
 * The failure this prevents has already shipped once here: a finished screen
 * whose route was never registered is dead code, and the only symptom is that
 * clicking the menu item lands on the router's "Halaman tidak ditemukan"
 * fallback — which reads to an operator as a permission problem or a bad deploy,
 * not as a missing line in app.js. The reverse drift is just as quiet: a working
 * screen with no NAV entry is reachable only by someone who already knows the
 * URL, so it is invisible to exactly the people it was built for.
 *
 * The check is a grep on purpose. There is no JS runtime on this host, and a
 * grep that reads the same two files a reviewer would read cannot itself drift
 * out of date the way a hand-kept list of expected routes would.
 */
class NavRouteRegistryTest extends ErpTestCase
{
    /** Every NAV item points at something the router can actually serve. */
    public function test_every_nav_entry_resolves_to_a_registered_screen(): void
    {
        $routes = $this->navRoutes();

        // A regex that silently stopped matching would turn this whole test into
        // a no-op that still reports PASS, which is worse than not having it.
        $this->assertGreaterThan(
            50,
            count($routes),
            'Only '.count($routes).' NAV routes were extracted from schema.js. The NAV shape has changed and '
            .'this test is no longer reading it — fix navRoutes() before trusting a green run.',
        );

        foreach ($routes as $route) {
            $this->assertTrue(
                $this->resolves($route),
                $this->missingMessage($route),
            );
        }
    }

    /**
     * The refused half: prove the matcher above actually says no to something.
     * A NAV entry pointing at a screen nobody registered must be reported, or
     * the works-test passes for every possible input and guarantees nothing.
     */
    public function test_a_nav_entry_with_no_registered_route_is_reported(): void
    {
        // Deliberately absent from app.js and from every RESOURCES table.
        $this->assertFalse($this->resolves('impor-dokumen-yang-tidak-pernah-didaftarkan'));
        $this->assertFalse($this->resolves('r/estimation/tidak-ada-tabel-ini'));

        // ...while the two shapes that DO resolve still pass, so the matcher is
        // not simply refusing everything: one plain screen route and one
        // RESOURCES-backed list route.
        $this->assertTrue($this->resolves('impor-dokumen'));
        $this->assertTrue($this->resolves('r/estimation/boqs'));
    }

    /**
     * P1-B: every NAV group carries a `prefix`, and that prefix resolves to a
     * module home — the breadcrumb's first crumb links to `#/m/<prefix>`, so
     * a group without one (or with one MODULES does not know) is a crumb that
     * lands on "Modul tidak dikenal". Same grep discipline as the routes above:
     * the group header line, the MODULES block, the route and the view file.
     */
    public function test_every_nav_group_prefix_resolves_to_a_module_home(): void
    {
        $headers = $this->groupHeaders($this->schema());

        $this->assertGreaterThan(10, count($headers),
            'Only '.count($headers).' NAV group headers were extracted from schema.js; the group header shape '
            .'has changed and this test is no longer reading it.');

        // Verifikasi P1-B (5 Sep 2026): the old reader only extracted headers
        // that STILL had a prefix and then asserted "> 10", so one group losing
        // its prefix (14 → 13) passed while its crumb silently degraded to a
        // plain <span>. Every header is read now, with or without a prefix,
        // and the ones without are named.
        $without = array_column(array_filter($headers, fn (array $header) => $header['prefix'] === null), 'label');
        $this->assertSame([], $without, 'NAV group(s) without a prefix: '.implode(', ', $without)
            .' — their module crumb is dead text and the group carries no accent.');

        $prefixes = array_column($headers, 'prefix');
        $this->assertSame(count($prefixes), count(array_unique($prefixes)), 'Two NAV groups share one prefix; their module homes would collide.');

        // MODULES and NAV must be the same set: an extra MODULES key is a module
        // home nobody can reach, a missing one is a crumb that lands on "Modul tidak dikenal".
        preg_match_all('/^  ([a-z]+): \{ accent: [1-8],/m', $this->modulesBlock(), $modules);
        sort($prefixes);
        sort($modules[1]);
        $this->assertSame($prefixes, $modules[1], 'schema.js MODULES keys and NAV group prefixes are not the same set.');

        $this->assertStringContainsString("route('m/:prefix'", $this->app(),
            "app.js has no route('m/:prefix', ...) — the module crumb points at the not-found fallback.");
        $this->assertFileExists(public_path('app/js/views/module.js'));
        $this->assertStringContainsString('export function renderModuleHome(', (string) file_get_contents(public_path('app/js/views/module.js')));
        $this->assertMatchesRegularExpression("/import \{[^}]*\brenderModuleHome\b[^}]*\} from '\.\/views\/module\.js'/", $this->app());

        foreach ($prefixes as $prefix) {
            $this->assertTrue($this->moduleResolves($prefix), sprintf(
                'NAV group prefix "%s" has no entry in schema.js MODULES, so #/m/%s renders "Modul tidak dikenal". '
                .'Add it to MODULES with its accent slot, icon and one-line description.',
                $prefix,
                $prefix,
            ));
        }
    }

    /** The refused half for the module-home matcher. */
    public function test_a_prefix_missing_from_modules_is_reported(): void
    {
        $this->assertFalse($this->moduleResolves('modul-yang-tidak-pernah-didaftarkan'));
        $this->assertTrue($this->moduleResolves('fin'));
        $this->assertTrue($this->moduleResolves('ringkasan'));
    }

    /**
     * The refused half for the header reader: strip one group's prefix from a
     * copy of schema.js and the reader must still see every header — and name
     * the one that lost its prefix — instead of quietly reading one group fewer.
     */
    public function test_a_nav_group_that_lost_its_prefix_is_reported(): void
    {
        $source = $this->schema();
        $stripped = str_replace("label: 'Aset', perm: 'ast.view', prefix: 'ast',", "label: 'Aset', perm: 'ast.view',", $source, $count);

        $this->assertSame(1, $count, 'The Aset group header no longer has the shape this test simulates on.');

        $before = $this->groupHeaders($source);
        $after = $this->groupHeaders($stripped);

        $this->assertCount(count($before), $after, 'A header without a prefix dropped out of the reader instead of being reported.');
        $this->assertSame(['Aset'], array_column(array_filter($after, fn (array $header) => $header['prefix'] === null), 'label'));
        $this->assertSame([], array_filter($before, fn (array $header) => $header['prefix'] === null));
    }

    /**
     * Bentuk entri lengkap, `kpi` ikut (P1-C): tanpa nama angka utamanya ubin
     * launcher yang angkanya tidak diketahui menulis '—' telanjang, tanpa
     * memberi tahu pembacanya angka apa yang tidak diketahui. Kesetaraan nama
     * itu dengan label registri server diuji ModuleCountsTest.
     */
    private function moduleResolves(string $prefix): bool
    {
        return (bool) preg_match('/^  '.preg_quote($prefix, '/').": \{ accent: [1-8], icon: '[a-z0-9-]+', kpi: '[^']+', description: '[^']+' \},$/m", $this->modulesBlock());
    }

    /** The `export const MODULES = {...}` block, so a RESOURCES key never passes for a module. */
    private function modulesBlock(): string
    {
        $source = $this->schema();
        $start = strpos($source, 'export const MODULES = {');

        $this->assertNotFalse($start, 'MODULES could not be found in schema.js; the module-home check can no longer run.');

        $end = strpos($source, "\n};", $start);

        return substr($source, $start, $end - $start);
    }

    /**
     * Every NAV group header line, with its prefix or null when the header has
     * none — the prefix is optional in the pattern on purpose (see the test).
     *
     * @return list<array{label: string, prefix: string|null}>
     */
    private function groupHeaders(string $source): array
    {
        $start = strpos($source, 'export const NAV = [');

        $this->assertNotFalse($start, 'NAV could not be found in schema.js; this test can no longer check anything.');

        preg_match_all("/^    label: '([^']+)', perm: [^,]+(?:, prefix: '([a-z]+)')?,$/m", substr($source, $start), $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => ['label' => $match[1], 'prefix' => isset($match[2]) && $match[2] !== '' ? $match[2] : null], $matches);
    }

    /**
     * A plain key is served by its own route() call in app.js; an `r/<key>` key
     * is served by the generic `r/*` list route, which resolves only if that key
     * exists in a RESOURCES table.
     */
    private function resolves(string $route): bool
    {
        if (str_starts_with($route, 'r/')) {
            $key = substr($route, 2);

            // Most live in schema.js; kaskecil.js registers its three at import
            // time (RESOURCES['key'] = {...}) and the generic list serves those
            // identically.
            return str_contains($this->schema(), "  '{$key}': {")
                || str_contains($this->views(), "RESOURCES['{$key}'] = {");
        }

        /*
         * `b/<resource>` — papan kanban (P1-G), keluarga wildcard KEDUA. Ia
         * butuh dua hal, bukan satu: entri RESOURCES-nya, DAN blok `board:` di
         * dalam entri itu. Rute b/* menjawab "Papan … tidak dikenal" untuk
         * resource yang ada tetapi tidak punya papan, dan sebuah baris menu ke
         * sana adalah pintu yang selalu tertutup.
         */
        if (str_starts_with($route, 'b/')) {
            $key = substr($route, 2);
            $at = strpos($this->schema(), "  '{$key}': {");

            if ($at === false) {
                return false;
            }

            $entry = substr($this->schema(), $at, (int) strpos($this->schema(), "\n  },", $at) - $at);

            return str_contains($entry, "\n    board: {");
        }

        return str_contains($this->app(), "route('{$route}'");
    }

    private function missingMessage(string $route): string
    {
        if (str_starts_with($route, 'b/')) {
            return sprintf(
                'NAV entry [%s] points at a kanban board, but "%s" has no `board: {` block in schema.js '
                .'(or no RESOURCES entry at all). The b/* route answers "Papan … tidak dikenal" for those, '
                .'so the menu row is a door that never opens.',
                $route,
                substr($route, 2),
            );
        }

        return str_starts_with($route, 'r/')
            ? sprintf(
                'NAV entry [%s] has no RESOURCES definition for "%s", so the generic list route renders '
                .'"Halaman tidak dikenal". Add the resource to schema.js RESOURCES.',
                $route,
                substr($route, 2),
            )
            : sprintf(
                'NAV entry [%s] has no route(\'%s\', ...) in public/app/js/app.js, so clicking the menu item '
                .'lands on the not-found fallback. Register the route beside its neighbours in registerRoutes().',
                $route,
                $route,
            );
    }

    /** @return list<string> */
    private function navRoutes(): array
    {
        $source = $this->schema();
        $start = strpos($source, 'export const NAV = [');

        $this->assertNotFalse($start, 'NAV could not be found in schema.js; this test can no longer check anything.');

        preg_match_all("/route: '([^']+)'/", substr($source, $start), $matches);

        return array_values(array_unique($matches[1]));
    }

    private function schema(): string
    {
        return (string) file_get_contents(public_path('app/js/schema.js'));
    }

    private function app(): string
    {
        return (string) file_get_contents(public_path('app/js/app.js'));
    }

    private function views(): string
    {
        $source = '';
        foreach (glob(public_path('app/js/views/*.js')) as $view) {
            $source .= (string) file_get_contents($view);
        }

        return $source;
    }
}
