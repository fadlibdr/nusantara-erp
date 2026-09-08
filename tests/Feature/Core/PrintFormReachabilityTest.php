<?php

namespace Tests\Feature\Core;

use Modules\Core\Services\FormPrintService;
use Modules\Core\Support\PrintableDocuments;
use ReflectionClass;
use Tests\ErpTestCase;

/**
 * A printable document and the button that reaches it live in two languages
 * with no build step between them: PHP declares the form, the SPA draws the
 * button, and nothing fails loudly when they drift.
 *
 * The failure this prevents has already shipped here twice. A form registered
 * on the server with nothing in the browser pointing at it is a document the
 * owner paid for and cannot print — the endpoint answers perfectly, the sheet
 * renders perfectly, and no screen offers it. The symptom is not an error: it
 * is an operator saying "the system does not have that form", about a form the
 * system has.
 *
 * THE CHECK IS A GREP ON PURPOSE, exactly as NavRouteRegistryTest says of its
 * own: there is no JS runtime on this host, and a grep that reads the same
 * files a reviewer would read cannot drift the way a hand-kept list of expected
 * screens would. It reads the three shapes the SPA actually uses, and the
 * refused half below proves it can still say no.
 */
class PrintFormReachabilityTest extends ErpTestCase
{
    /**
     * The eight bespoke forms — FormPrintService::FORMS — each have a button.
     *
     * Two shapes: a `form: '<slug>'` entry in a schema.js printForms list or a
     * project.js form descriptor, which is how a form that hangs off a listed
     * record gets its button; or a hard-coded core/print/forms/<slug>/ call,
     * which is how a screen that has to CHOOSE the record first (data proyek)
     * draws its own.
     */
    /**
     * `.lembar` ADALAH KONTRAK ANTARA SETIAP LEMBAR DAN print.js.
     *
     * printWhenLoaded() menunggu `tab.document.querySelector('.lembar')`
     * sebelum memanggil tab.print() — readyState saja tidak cukup, karena
     * about:blank sudah 'complete' dan yang tercetak akan menjadi halaman
     * penampung. Sebuah lembar tanpa pembungkus itu tergambar sempurna di tab
     * barunya dan dialog cetaknya TIDAK PERNAH muncul; sesudah ~7 detik
     * PRINT_POLL_LIMIT menyerah tanpa satu pun pesan, dan di gudang itu
     * terbaca sebagai "tombol cetaknya rusak". Persis yang terjadi pada
     * label-barcode, satu-satunya lembar yang tidak mewarisi forms.layout.
     *
     * Diperiksa dari SUMBER dan bukan dari satu render, supaya lembar bespoke
     * kesembilan tidak bisa lahir tanpa kontraknya.
     */
    public function test_every_bespoke_sheet_carries_the_wrapper_print_js_waits_for(): void
    {
        $polled = (string) file_get_contents(public_path('app/js/print.js'));
        $this->assertStringContainsString(".querySelector('.lembar')", $polled,
            'print.js tidak lagi menunggu .lembar; kontraknya pindah dan uji ini harus ikut pindah.');

        foreach ($this->bespokeSlugs() as $slug) {
            $blade = (string) file_get_contents(
                base_path("Modules/Core/Resources/views/forms/{$slug}.blade.php"),
            );

            $inherits = str_contains($blade, "@extends('coredoc::forms.layout')")
                || str_contains($blade, '@extends(\'coredoc::forms.layout\')');

            $this->assertTrue(
                $inherits || str_contains($blade, 'class="lembar"'),
                "Lembar [{$slug}] tidak mewarisi forms.layout dan tidak membawa `class=\"lembar\"` sendiri: "
                .'print.js tidak akan pernah tahu lembarnya sudah mendarat, jadi dialog cetak tidak pernah muncul '
                .'dan tidak ada satu pun pesan yang menjelaskannya.',
            );
        }
    }

    public function test_every_bespoke_form_has_a_button_somewhere_in_the_spa(): void
    {
        $slugs = $this->bespokeSlugs();

        // A reflection that silently stopped reading FORMS would turn this into
        // a no-op that still reports PASS, which is worse than no test.
        /*
         * DELAPAN, ANGKA LITERAL, dan ia NAIK BERSAMA PAKETNYA — pola yang
         * sama dengan PrintCatalogueBespokeTest::assertCount(65, …).
         *
         * Ambang ini dipasang supaya "refleksi yang diam-diam berhenti membaca
         * FORMS" tidak berubah menjadi no-op yang tetap melapor PASS. Dengan
         * delapan formulir dan ambang tujuh, penjaganya longgar satu: satu
         * formulir yang hilang dari FORMS lolos tanpa suara — penjaga yang
         * menjaga persis satu langkah lebih sedikit daripada yang dijanjikan
         * komentarnya. F-6 menambahkan label-barcode dan tidak menaikkannya.
         */
        $this->assertGreaterThanOrEqual(
            8,
            count($slugs),
            'Only '.count($slugs).' bespoke forms were read out of FormPrintService::FORMS. The constant has '
            .'moved and this test is no longer reading it — fix bespokeSlugs() before trusting a green run.',
        );

        foreach ($slugs as $slug) {
            $this->assertTrue(
                $this->formIsWired($slug),
                sprintf(
                    'Formulir [%s] is registered in FormPrintService::FORMS and no screen offers it: no '
                    ."`form: '%s'` entry in schema.js or views/, and no core/print/forms/%s/ call. It can be "
                    .'printed only by typing the URL.',
                    $slug,
                    $slug,
                    $slug,
                ),
            );
        }
    }

    /**
     * Every declarative document reaches a screen that can draw its button.
     *
     * The catalogue endpoint answers per RESOURCE, so the generic list and
     * detail screens draw the button for any entry whose resource is a
     * RESOURCES key; a custom detail screen asks for the same thing by hand
     * through houseFormButtons(resource); and a register whose anchor row the
     * screen has to pick — the attendance sheet, the tax calendar — calls
     * core/print/forms/<slug>/ itself. Anything matching none of the three is
     * a document with no way in.
     */
    public function test_every_registry_document_reaches_a_screen_that_can_draw_its_button(): void
    {
        $registry = app(PrintableDocuments::class);
        $slugs = $registry->keys();

        $this->assertGreaterThanOrEqual(
            30,
            count($slugs),
            'Only '.count($slugs).' registry documents were found. PrintableDocuments has changed shape and '
            .'this test is no longer reading it.',
        );

        foreach ($slugs as $slug) {
            $resource = $registry->definition($slug)['resource'];

            $this->assertTrue(
                $this->resourceIsWired($resource) || $this->formIsWired($slug),
                sprintf(
                    'Dokumen cetak [%s] declares resource "%s", which is not a RESOURCES key in schema.js, is '
                    .'not registered as RESOURCES[\'%s\'] by a view, is not passed to houseFormButtons(), and '
                    .'no screen calls core/print/forms/%s/. Nothing in the browser can print it.',
                    $slug,
                    $resource,
                    $resource,
                    $slug,
                ),
            );
        }
    }

    /**
     * The refused half. Without it the two tests above pass for every possible
     * input — including a matcher that has quietly started saying yes to
     * everything — and guarantee nothing.
     */
    public function test_a_form_nobody_wired_is_reported(): void
    {
        $this->assertFalse($this->formIsWired('formulir-yang-tidak-pernah-dibuat'));
        $this->assertFalse($this->resourceIsWired('estimation/tabel-yang-tidak-ada'));

        // ...while the shapes that DO exist still pass, so the matchers are not
        // simply refusing everything: one bespoke form reached through a schema
        // printForms entry, one through a hard-coded call, one RESOURCES-backed
        // resource and one custom screen's houseFormButtons.
        $this->assertTrue($this->formIsWired('laporan-harian'));
        $this->assertTrue($this->formIsWired('data-proyek'));
        $this->assertTrue($this->resourceIsWired('crm/quotations'));
        $this->assertTrue($this->resourceIsWired('assets/assets'));
    }

    private function formIsWired(string $slug): bool
    {
        return str_contains($this->js(), "form: '{$slug}'")
            || str_contains($this->js(), "core/print/forms/{$slug}/");
    }

    private function resourceIsWired(string $resource): bool
    {
        return str_contains($this->schema(), "  '{$resource}': {")
            || str_contains($this->js(), "RESOURCES['{$resource}'] = {")
            || str_contains($this->js(), "houseFormButtons('{$resource}'");
    }

    /** @return list<string> */
    private function bespokeSlugs(): array
    {
        $forms = (new ReflectionClass(FormPrintService::class))->getConstant('FORMS');

        $this->assertIsArray($forms, 'FormPrintService::FORMS is no longer an array constant.');

        return array_keys($forms);
    }

    private function schema(): string
    {
        return (string) file_get_contents(public_path('app/js/schema.js'));
    }

    /** Every shipped SPA module, concatenated — the button may be drawn in any of them. */
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
