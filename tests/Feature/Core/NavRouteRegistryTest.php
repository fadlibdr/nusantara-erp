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

        return str_contains($this->app(), "route('{$route}'");
    }

    private function missingMessage(string $route): string
    {
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
