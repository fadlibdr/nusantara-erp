<?php

namespace Tests\Feature\Crm;

use Modules\Crm\Http\Requests\RkkSmkkCostsRequest;
use Tests\ErpTestCase;

/**
 * P7 — the browser half of the tender package, pinned the way this repo pins
 * every other two-language seam: by grep, because there is no JS runtime on
 * this host and a grep reads the same files a reviewer would.
 *
 * THREE FAILURES THIS PREVENTS, all of which have shipped here before:
 *
 * 1. AN ENDPOINT WITH NO SCREEN. `crm/tender-qualification/*` answers three
 *    questions perfectly and, until this package's SPA lane, nothing in the
 *    browser asked any of them. That is the mirror image of the drift
 *    NavRouteRegistryTest catches — a menu item with no route is a dead link,
 *    an API with no menu item is a feature the owner paid for and cannot see.
 *
 * 2. A SCREEN WITH NO MENU ENTRY. `core/method-library` is a RESOURCES key
 *    reachable by typing its hash; that is not reachable.
 *
 * 3. THE HONESTY WORDING QUIETLY DELETED. The sentences asserted below are not
 *    decoration: "BELUM DINILAI" is what stops an unpriced TKDN line reading as
 *    0%, and the vanished-source line is what stops a deleted IBPRP row reading
 *    as a row that was never there. A refactor that drops them leaves a screen
 *    that still works and no longer tells the truth, and nothing else in the
 *    suite would notice.
 */
class TenderSpaWiringTest extends ErpTestCase
{
    /**
     * The qualification composer: a screen, a route, and a menu entry — and it
     * asks all three questions the server can answer, not one of them.
     */
    public function test_the_qualification_composer_is_reachable_and_asks_all_three_endpoints(): void
    {
        $js = $this->js();

        foreach (['personnel', 'equipment', 'subcontractors'] as $endpoint) {
            $this->assertStringContainsString(
                "crm/tender-qualification/{$endpoint}",
                $js,
                "Endpoint crm/tender-qualification/{$endpoint} is answered by the server and asked by nothing "
                .'in the browser. The qualification annexes are assembled from three masters; a screen that '
                .'reads two of them composes a bid that is missing a section.',
            );
        }

        $this->assertStringContainsString(
            "route('kualifikasi'",
            $this->app(),
            "NAV points at 'kualifikasi' but app.js registers no route for it, so the menu item lands on the "
            .'not-found fallback.',
        );

        $this->assertStringContainsString(
            "route: 'kualifikasi'",
            $this->navBlock(),
            'The qualification composer has no NAV entry, so it is reachable only by someone who already '
            .'knows the URL — invisible to exactly the tender team it was built for.',
        );
    }

    /** The method library is a screen people can find, not a hash they must know. */
    public function test_the_method_library_has_a_menu_entry(): void
    {
        $this->assertStringContainsString(
            "route: 'r/core/method-library'",
            $this->navBlock(),
            'RESOURCES holds core/method-library and NAV does not point at it. A metode pelaksanaan nobody '
            .'can navigate to is a library nobody fills.',
        );
    }

    /**
     * TKDN and RKK get real screens, not the generic detail.
     *
     * Both compose rows that belong to somebody else — the quotation's own
     * lines, the project's risk register, the RAB's cost lines — and the
     * generic `lines` grid can only offer a raw id field for each. A raw id
     * field on a bid document is a typo away from citing another project's
     * hazard assessment.
     */
    public function test_the_tkdn_and_rkk_screens_are_wired_as_custom_details(): void
    {
        $schema = $this->schema();
        $app = $this->app();

        foreach ([
            'crm/tkdn-worksheets' => 'tkdn',
            'crm/rkk-documents' => 'rkk',
        ] as $resource => $handle) {
            $this->assertStringContainsString(
                "customDetail: '{$handle}'",
                $schema,
                "RESOURCES['{$resource}'] does not declare customDetail: '{$handle}', so its detail screen is "
                .'the generic one and its pickers are raw id boxes.',
            );

            $this->assertMatchesRegularExpression(
                '/CUSTOM_DETAILS = \{[^}]*\b'.preg_quote($handle, '/').':/s',
                $app,
                "customDetail: '{$handle}' resolves to nothing in CUSTOM_DETAILS, so the detail route falls "
                .'back to the generic screen and the custom one is dead code.',
            );
        }
    }

    /** The RKK screen writes through the two endpoints built for it, not through the record. */
    public function test_the_rkk_screen_links_ibprp_and_smkk_through_their_own_endpoints(): void
    {
        $js = $this->js();

        foreach (['ibprp-links', 'smkk-costs'] as $endpoint) {
            $this->assertStringContainsString(
                "/{$endpoint}",
                $js,
                "Nothing in the browser calls rkk-documents/{id}/{$endpoint}. The links can then be made only "
                .'by curl, which means in practice they are never made.',
            );
        }
    }

    /**
     * THE SMKK PICKER NEVER OFFERS A RUPIAH FIELD, and the proof is that the
     * key list the screen actually posts with is the key list the server
     * actually accepts.
     *
     * The value of an SMKK cost line IS the value of the RAB line it points at
     * (migration 000392). A rupiah box on this screen would be a second number
     * for the same money, free to drift from the RAB signed beside it — and
     * because the sheet totals what the screen sends, the drift would print.
     */
    public function test_the_smkk_payload_the_screen_sends_is_exactly_what_the_server_accepts(): void
    {
        $declared = $this->smkkPayloadKeys();

        $this->assertNotContains(
            'amount',
            $declared,
            'The SMKK picker declares an amount key. The amount of an SMKK line is the amount of its RAB '
            .'line; a typed one is a second number for the same money.',
        );

        $accepted = [];
        foreach (array_keys((new RkkSmkkCostsRequest)->rules()) as $rule) {
            if (str_starts_with($rule, 'smkk_costs.*.')) {
                $accepted[] = substr($rule, strlen('smkk_costs.*.'));
            }
        }

        $this->assertNotEmpty($accepted, 'RkkSmkkCostsRequest no longer names per-row keys; this test reads nothing.');

        $this->assertSame(
            [],
            array_values(array_diff($declared, $accepted)),
            'The SMKK picker posts keys the server does not accept: '
            .implode(', ', array_diff($declared, $accepted)),
        );
    }

    /**
     * The honesty wording, on the screen and not only on the sheet.
     *
     * An operator reads the screen far more often than the printed form; a
     * ruled cell that is honest on paper and blank-looking in the browser
     * teaches the operator the wrong thing about their own data.
     */
    public function test_the_screens_say_unassessed_and_missing_out_loud(): void
    {
        $view = $this->tenderView();

        $this->assertStringContainsString(
            'BELUM DINILAI',
            $view,
            'A quotation line with no cost breakdown must read BELUM DINILAI on screen. Without the words it '
            .'reads as a line worth 0% domestic content, which is a claim nobody made.',
        );

        $this->assertStringContainsString(
            'item dinilai',
            $view,
            'The package percentage must carry its coverage ("n dari m item dinilai") wherever it is shown. A '
            .'TKDN percentage without its coverage is the number a bid document should never carry alone.',
        );

        $this->assertStringContainsString(
            'tidak ditemukan',
            $view,
            'A linked IBPRP row or SMKK cost line whose source has been deleted must say so. Dropping the row '
            .'silently makes the sheet read complete.',
        );

        $this->assertStringContainsString(
            'kedaluwarsa',
            $view,
            'The qualification composer must name the lapsed certificates it is holding back. Hiding them is '
            .'how a bid team discovers the gap after losing the tender.',
        );

        // The plant mirror of the same rule. It is asserted separately from the
        // certificate one because the two are held back by two different pieces
        // of code, and a refactor that keeps one bucket is exactly the shape of
        // regression this file exists to catch.
        $this->assertStringContainsString(
            'Sewa berakhir',
            $view,
            'The qualification composer must name the rented plant it is holding back because the lease has '
            .'ended. Nothing in Assets moves a rented asset off `available` when its lease expires, so a '
            .'screen that only filters silently drops machines that are already back with the lessor.',
        );

        $this->assertStringContainsString(
            "'crm/tender-qualification/equipment', { as_of:",
            $view,
            'The plant table must be answered as at the screen\'s own reference date, like the personnel '
            .'table beside it. A lease ends the way a certificate lapses; two tables on one screen answering '
            .'two different dates is the disagreement nobody notices until it is printed.',
        );

        $this->assertStringContainsString(
            'DINILAI SEBAGIAN',
            $view,
            'A quotation line whose cost rows are far smaller than the line itself is neither BELUM DINILAI '
            .'nor plainly "Dinilai". Without the third word on screen a Rp 1 cost row reads as a fully '
            .'assessed line, which is the one way this sheet can lie without anybody mistyping a number.',
        );

        // The LIST is where a reader meets a worksheet first, and it already
        // carries coverage. Carrying two of the three buckets there prints
        // "cakupan 0% · belum dinilai 5,6 M" over a sheet that has really
        // described 4,2 M — ignorance overstated is still a wrong number.
        $this->assertStringContainsString(
            'summary.partially_assessed_value',
            $this->schema(),
            'The TKDN list shows coverage_pct and unassessed_value; the partially-assessed bucket must ride '
            .'along, or the three buckets stop adding up to the offer value on the one screen most people read.',
        );
    }

    /**
     * The refused half. Without it every assertion above passes for any input,
     * including a reader that has quietly started returning the whole repo.
     */
    public function test_the_readers_can_still_say_no(): void
    {
        $this->assertStringNotContainsString('route(\'kualifikasi-yang-tidak-pernah-dibuat\'', $this->app());
        $this->assertStringNotContainsString("route: 'r/crm/tabel-yang-tidak-ada'", $this->navBlock());
        $this->assertStringNotContainsString('crm/tender-qualification/tidak-ada', $this->js());

        // ...and the NAV reader really is reading only NAV, not the whole file:
        // 'crm/tender-packages' is a RESOURCES key far above the NAV block, and
        // its bare form must not be mistaken for a menu entry.
        $this->assertStringNotContainsString("route: 'r/crm/quotation-items'", $this->navBlock());
        $this->assertStringContainsString("route: 'r/crm/quotations'", $this->navBlock());
    }

    /**
     * The key list the screen posts to /smkk-costs, read out of the constant
     * the screen actually builds its payload from.
     *
     * @return list<string>
     */
    private function smkkPayloadKeys(): array
    {
        preg_match(
            '/export const SMKK_PAYLOAD_KEYS = \[([^\]]*)\]/',
            $this->tenderView(),
            $matches,
        );

        $this->assertNotEmpty(
            $matches,
            'SMKK_PAYLOAD_KEYS is gone from the tender view. It is the constant the screen builds its payload '
            .'from; without it this test reads nothing and the no-rupiah rule is unpinned.',
        );

        preg_match_all("/'([^']+)'/", $matches[1], $keys);

        return $keys[1];
    }

    /** Only the NAV block, so a RESOURCES key is never mistaken for a menu entry. */
    private function navBlock(): string
    {
        $source = $this->schema();
        $start = strpos($source, 'export const NAV = [');

        $this->assertNotFalse($start, 'NAV could not be found in schema.js; this test can no longer check anything.');

        return substr($source, $start);
    }

    private function tenderView(): string
    {
        $path = public_path('app/js/views/tender.js');

        $this->assertFileExists(
            $path,
            'public/app/js/views/tender.js is missing: the tender screens (TKDN, RKK, penyusun kualifikasi) '
            .'have no module.',
        );

        return (string) file_get_contents($path);
    }

    private function schema(): string
    {
        return (string) file_get_contents(public_path('app/js/schema.js'));
    }

    private function app(): string
    {
        return (string) file_get_contents(public_path('app/js/app.js'));
    }

    /** Every shipped SPA module, concatenated — the wiring may live in any of them. */
    private function js(): string
    {
        $source = '';

        foreach ([public_path('app/js/*.js'), public_path('app/js/views/*.js')] as $pattern) {
            foreach (glob($pattern) as $file) {
                $source .= (string) file_get_contents($file);
            }
        }

        return $source;
    }
}
