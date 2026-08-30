<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Core\Services\FormXlsxExportService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P8 — the browser half of the cross-module package, pinned the way
 * TenderSpaWiringTest pins P7: by grep, because there is no JS runtime on this
 * host and a grep reads the same files a reviewer would.
 *
 * WHAT THIS FILE HOLDS TOGETHER:
 *
 * 1. THE XLSX BUTTON HAS EXACTLY ONE OWNER. The ten exportable slugs live in
 *    FormXlsxExportService::FORMS and travel to the browser as an `xlsx` flag
 *    on the print catalogue. The moment any SPA file names a slug, the list
 *    has two owners and the eleventh form added in PHP ships without its
 *    button — the print catalogue's own lesson, relearned.
 *
 * 2. AN ENDPOINT WITH NO SCREEN. Four legacy importers, an MPP-XML import,
 *    three revise endpoints and a rate-history read all answered perfectly
 *    before this lane, and nothing in the browser asked any of them.
 *
 * 3. A MENU THAT HIDES A WORKING SCREEN. The import screen renders whatever
 *    GET core/document-import answers — but its menu entry was gated on
 *    crm/est only, so the warehouse clerk whose stock cards the P8 importers
 *    exist for would never see the door.
 *
 * 4. THE SUPERSEDED BANNER SAYS WHAT THE 422 SAYS. A reader who opens an old
 *    revision must read on screen the same fact the server would refuse with,
 *    and be handed the way to the live row — not discover it by pressing
 *    Ajukan and being told off.
 */
class CrossModuleSpaWiringTest extends ErpTestCase
{
    /* ================================================== XLSX (D6 sisa) === */

    public function test_the_xlsx_button_rides_the_catalogue_flag_not_a_hardcoded_list(): void
    {
        $catalog = $this->file('printcatalog.js');

        $this->assertStringContainsString(
            'xlsx: entry.xlsx === true',
            $catalog,
            'printFormsFor() drops the catalogue\'s xlsx flag, so no screen can know which forms answer at '
            .'…/xlsx and the ten export buttons never draw.',
        );

        $this->assertStringContainsString(
            'export function xlsxPath',
            $catalog,
            'There is no xlsxPath() beside printablePath(). The export URL is the print URL with an /xlsx '
            .'tail BEFORE the query — hand-built per screen it will lose the ?tanggal= that the laporan '
            .'harian button carries, and export a different day than the sheet being looked at.',
        );

        foreach (['detail.js' => 'views/detail.js', 'list.js' => 'views/list.js'] as $label => $path) {
            $view = $this->file($path);

            $this->assertStringContainsString(
                'form.xlsx',
                $view,
                "{$label} draws print buttons and never reads the xlsx flag, so the screens it renders "
                .'(detail pages, and the noDetail rows that are some forms\' only home) have no export button.',
            );

            $this->assertStringContainsString(
                'sel kosong, bukan 0',
                $view,
                "The XLSX button in {$label} must carry the honesty sentence: a ruled cell on paper is an "
                .'EMPTY cell in the spreadsheet, never 0. The button tooltip is where an operator meets that '
                .'rule before Excel sums a column of invented zeroes.',
            );
        }

        // The one-owner rule, refused half: no SPA wiring file names a slug.
        foreach (FormXlsxExportService::FORMS as $slug) {
            foreach (['printcatalog.js', 'views/detail.js', 'views/list.js', 'print.js'] as $path) {
                $this->assertStringNotContainsString(
                    "'{$slug}'",
                    $this->file($path),
                    "{$path} names the slug {$slug}. The exportable list has one owner "
                    .'(FormXlsxExportService::FORMS, served via the catalogue); a copy in the browser is the '
                    .'copy that goes stale.',
                );
            }
        }
    }

    /* ============================================ importer warisan (#10) === */

    public function test_the_import_screen_renders_the_four_legacy_importers_from_the_endpoint(): void
    {
        $screen = $this->file('views/dokumenimpor.js');

        $this->assertStringContainsString(
            "api.get('core/document-import')",
            $screen,
            'The import screen no longer asks the registry endpoint; the four legacy importers then need '
            .'hand-wiring, which is exactly what the endpoint exists to avoid.',
        );

        foreach (['daily-reports', 'stock-cards', 'sp3', 'progress-pay'] as $key) {
            $this->assertStringNotContainsString(
                "'{$key}'",
                $screen,
                "dokumenimpor.js names the importer {$key}. Entries ride GET core/document-import; a "
                .'hardcoded key is a second registry that drifts.',
            );
        }

        // The endpoint really serves them to a caller with the owning modules'
        // view permission — reachability is server truth, not screen faith.
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $keys = collect($this->actingAs($this->userWith(['prj.view', 'inv.view', 'scm.view']))
            ->getJson('/api/core/document-import')
            ->assertOk()
            ->json('data'))->pluck('key');

        foreach (['daily-reports', 'stock-cards', 'sp3', 'progress-pay'] as $key) {
            $this->assertContains($key, $keys, "GET core/document-import does not list {$key} for a caller "
                .'holding the owning module\'s view permission.');
        }

        $this->assertNotContains('quotations', $keys,
            'A prj/inv/scm viewer is served the CRM quotations importer; the permission filter is gone.');
    }

    public function test_the_import_menu_admits_every_module_that_now_imports(): void
    {
        preg_match("/\{[^}]*route: 'impor-dokumen'[^}]*\}/s", $this->navBlock(), $entry);

        $this->assertNotEmpty($entry, "NAV has no entry routing to 'impor-dokumen'; the import screen is "
            .'reachable only by typing its hash.');

        foreach (['crm.create', 'est.create', 'prj.create', 'inv.create', 'scm.create', 'qc.create'] as $perm) {
            $this->assertStringContainsString(
                "'{$perm}'",
                $entry[0],
                "The Impor Dokumen menu entry does not admit {$perm}. The screen serves that module's "
                .'importer, but its clerk never sees the menu item — a working feature behind an invisible door.',
            );
        }
    }

    /* ==================================================== MPP-XML (#8) === */

    public function test_the_mpp_xml_import_is_reachable_from_the_project_workspace(): void
    {
        $screen = $this->file('views/project.js');

        $this->assertStringContainsString(
            'import-mpp-xml',
            $screen,
            'Nothing in the browser posts to projects/{id}/import-mpp-xml. The importer then runs only by '
            .'curl, which means in practice a schedule is never imported.',
        );

        foreach (['buat_baseline', 'bac_override'] as $key) {
            $this->assertStringContainsString(
                $key,
                $screen,
                "The MPP-XML dialog never sends {$key}; the server's option exists and no operator can "
                .'reach it.',
            );
        }

        $this->assertStringContainsString(
            'XML Format',
            $screen,
            'The dialog must tell the operator HOW to produce the file (Microsoft Project: File > Save As > '
            .'XML Format) — the server refuses binary .mpp with that same sentence, and the screen saying it '
            .'first saves the round trip.',
        );

        // The controller decodes STRICT base64; a FileReader dataURL prefix
        // ("data:…;base64,") fails that decode. The screen must send the naked
        // payload — this pins the split that strips the prefix.
        $this->assertStringContainsString(
            "split(',')",
            $screen,
            'The MPP-XML upload sends the FileReader dataURL as-is; base64_decode($content, true) on the '
            .'server refuses it and every upload dies with "Isi berkas bukan base64 yang dapat dibaca."',
        );
    }

    /* ============================================== revisi generik (D9) === */

    public function test_the_three_documents_wear_the_revision_pattern_on_screen(): void
    {
        // The revise action is declared ONCE, inside the revisableActions()
        // helper — that is where the endpoint path lives, so three screens
        // cannot drift three ways.
        preg_match(
            '/function revisableActions\(.*?\n\}/s',
            $this->file('schema.js'),
            $helper,
        );

        $this->assertNotEmpty($helper, 'schema.js no longer defines revisableActions(); the D9 wiring is gone.');
        $this->assertStringContainsString("'{id}/revise'", $helper[0],
            'revisableActions() does not post to {id}/revise, so the Buat Revisi button calls nothing.');
        $this->assertStringContainsString('navigateToResult', $helper[0],
            'Buat Revisi does not navigate to the successor; the operator lands back on the dead row they '
            .'just superseded.');

        // The marker column is likewise declared once (revisionColumn) and
        // spelled out here once: superseded rows read "Digantikan", amber.
        $this->assertMatchesRegularExpression(
            "/const revisionColumn = \{[^}]*falseLabel: 'Digantikan'/s",
            $this->file('schema.js'),
            'revisionColumn no longer marks superseded rows "Digantikan"; two rows with one work '
            .'description and no badge between them is how the wrong revision gets approved.',
        );

        foreach ([
            'projects/work-permits' => 'prj',
            'engineering/ipp' => 'eng',
            'quality/inspections' => 'qc',
        ] as $resource => $module) {
            $block = $this->resourceBlock($resource);

            $this->assertStringContainsString(
                "revisableActions('{$module}'",
                $block,
                "RESOURCES['{$resource}'] does not wrap its lifecycle actions in revisableActions(), so a "
                .'superseded row still offers Ajukan/Setujui — buttons whose only possible answer is a 422.',
            );

            $this->assertStringContainsString(
                'revisionColumn',
                $block,
                "The {$resource} list does not carry revisionColumn, so superseded rows sit unmarked "
                .'between live ones.',
            );

            $this->assertStringContainsString(
                'revisable: true',
                $block,
                "RESOURCES['{$resource}'] does not declare revisable: true, so the generic detail never "
                .'draws the superseded banner for it.',
            );
        }

        $detail = $this->file('views/detail.js');

        // The banner speaks the 422's own words (Revisable::assertRevisiBerlaku)
        // and hands the reader the live row instead of letting them find the
        // refusal by pressing Ajukan.
        $this->assertStringContainsString('telah digantikan revisi', $detail,
            'The generic detail has no superseded banner; an old revision reads exactly like the live one.');
        $this->assertStringContainsString('Buka revisi terbarunya', $detail,
            'The superseded banner does not link to the successor; the reader is told the row is dead and '
            .'left to hunt the live one through the list.');
        $this->assertStringContainsString('def.revisable', $detail,
            'The banner is not gated on the schema\'s revisable flag, so it will fire for every resource '
            .'that happens to expose is_current — including baselines and the method library, whose own '
            .'screens already tell this story their own way.');
    }

    /* ============================== {PROJ} & riwayat tarif (D2 dan D5) === */

    public function test_the_settings_screen_explains_proj_and_serves_the_rate_history(): void
    {
        $settings = $this->file('views/settings.js');

        $this->assertStringContainsString(
            '{PROJ}',
            $settings,
            'The mask preview does not acknowledge {PROJ}. An administrator typing the token reads a broken '
            .'preview and concludes the feature does not exist.',
        );

        $this->assertStringContainsString(
            'menolak menerbitkan nomor',
            $settings,
            'The {PROJ} hint must say what happens on a mask without a project: the mint FAILS LOUDLY. '
            .'Without that sentence the first refused document number reads as a server bug.',
        );

        $this->assertStringContainsString(
            "'core/rate-history'",
            $settings,
            'Nothing in the browser reads GET core/rate-history; the D5 history table is a feature the '
            .'owner paid for and cannot see.',
        );

        $this->assertStringContainsString(
            'snapshot per dokumen',
            $settings,
            'The rate-history card must say the history is record-only — snapshot per dokumen tetap sumber '
            .'kebenaran. Without the sentence the table reads as the thing that reprices old documents.',
        );
    }

    /* ================================================== the refused half == */

    public function test_the_readers_can_still_say_no(): void
    {
        // The block reader really isolates one entry: the SDS entry keeps its
        // own revision story (a new SDS row, not a revise endpoint) and must
        // not satisfy the D9 assertions by accident.
        $sds = $this->resourceBlock('engineering/drawing-submittals');
        $this->assertStringNotContainsString("'{id}/revise'", $sds);
        $this->assertStringNotContainsString('revisable: true', $sds);

        // Quotations already had revise before P8 and keep their own pattern —
        // not the revisableActions wrapper.
        $this->assertStringNotContainsString('revisableActions(', $this->resourceBlock('crm/quotations'));

        // And the flag never defaults on: a catalogue row without xlsx draws
        // no button.
        $this->assertStringNotContainsString('xlsx: true', $this->file('printcatalog.js'));

        $this->assertStringNotContainsString("route: 'r/core/rate-history'", $this->navBlock(),
            'Rate history grew its own menu entry; it belongs on the settings screen beside the rates it '
            .'records, and a second screen is a second place to forget.');
    }

    /* ------------------------------------------------------------ helpers */

    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pengguna',
            'email' => Str::random(8).'@nusantara.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** One RESOURCES entry, so a neighbour's wiring never satisfies an assertion. */
    private function resourceBlock(string $key): string
    {
        $schema = $this->file('schema.js');
        $start = strpos($schema, "'{$key}': {");

        $this->assertNotFalse($start, "RESOURCES['{$key}'] is gone from schema.js; the screen has no entry.");

        $end = strpos($schema, "\n  '", $start + 1);

        return substr($schema, $start, $end === false ? null : $end - $start);
    }

    /** Only the NAV block, so a RESOURCES key is never mistaken for a menu entry. */
    private function navBlock(): string
    {
        $schema = $this->file('schema.js');
        $start = strpos($schema, 'export const NAV = [');

        $this->assertNotFalse($start, 'NAV could not be found in schema.js; this test can no longer check anything.');

        return substr($schema, $start);
    }

    private function file(string $relative): string
    {
        $path = public_path('app/js/'.$relative);

        $this->assertFileExists($path, "public/app/js/{$relative} is missing.");

        return (string) file_get_contents($path);
    }
}
