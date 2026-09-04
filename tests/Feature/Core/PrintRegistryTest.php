<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Carbon;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Core\Support\PrintableDocuments;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Models\QuotationItem;
use Modules\Projects\Models\Project;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * The declarative print registry — the engine that turns "print button on every
 * module" from forty bespoke composers into forty array entries.
 *
 * Seven house forms took seven private methods and seven Blades. Forty cannot
 * work that way, and they do not have to: nearly every house form is the same
 * skeleton — four-party band, identity block, one or two bordered body tables,
 * notes, three signature columns, form code — differing only in its title, its
 * columns and where the rows come from. PrintableDocuments declares those
 * differences; generic.blade.php renders them; nothing else is written per
 * document.
 *
 * THE HONESTY RULE IS UNCHANGED AND IS WHAT MOST OF THIS FILE TESTS. A cell is
 * printed from the database or printed as a ruled blank. The declarative path
 * makes that rule cheaper to break than the bespoke one did — a resolver that
 * returns null is one keystroke away from a resolver that returns 0 — so the
 * assertions below check the blanks as hard as they check the values.
 */
class PrintRegistryTest extends ErpTestCase
{
    private FormPrintService $forms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forms = app(FormPrintService::class);

        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'legal_name' => 'PT Nusantara Karya Integrasi',
            'npwp' => '01.234.567.8-012.000',
            'is_pkp' => true,
            'address' => 'Jl. Raya Cakung Cilincing KM 2 No. 88',
            'city' => 'Jakarta Timur',
            'province' => 'DKI Jakarta',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::query()->create([
            'name' => 'PT Angkasa Pura I (Persero)',
            'is_pkp' => true,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
    }

    /**
     * A penawaran with two lines whose arithmetic can be checked by hand:
     * 2 × 125.000.000 + 1 × 47.500.000 = 297.500.000.
     */
    private function quotation(array $attributes = []): Quotation
    {
        $quotation = Quotation::query()->create(array_merge([
            'code' => 'QTN/2026/VIII/0007',
            'customer_id' => $this->customer()->id,
            'title' => 'Pengadaan dan Pemasangan Sistem CCTV Terminal 2',
            'scope_type' => 'system_integration',
            'valid_until' => '2026-09-30',
            'subtotal' => 297_500_000,
            'discount_amount' => 7_500_000,
            'dpp' => 290_000_000,
            'ppn_rate' => 11.0,
            'ppn_amount' => 31_900_000,
            'total' => 321_900_000,
            'status' => 'approved',
            'revision' => 0,
        ], $attributes));

        QuotationItem::query()->create([
            'quotation_id' => $quotation->id,
            'line_no' => 1,
            'description' => 'Kamera IP fixed dome 4MP, termasuk bracket',
            'qty' => 2,
            'unit' => 'unit',
            'unit_price' => 125_000_000,
            'amount' => 250_000_000,
        ]);

        QuotationItem::query()->create([
            'quotation_id' => $quotation->id,
            'line_no' => 2,
            'description' => 'NVR 32 kanal dengan storage 24 TB',
            'qty' => 1,
            'unit' => 'unit',
            'unit_price' => 47_500_000,
            'amount' => 47_500_000,
        ]);

        return $quotation->fresh();
    }

    private function userWithout(string $permission)
    {
        $user = $this->adminUser();
        $user->roles->first()->revokePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->refresh();
    }

    // -------------------------------------------------------- the catalogue

    /**
     * The catalogue is what removes forty schema.js edits: the SPA asks the
     * server which documents this caller may print and renders a button per
     * answer, so a module lane adds a registry row and the button appears.
     */
    public function test_the_catalogue_names_the_resource_the_button_belongs_to(): void
    {
        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/core/print/forms')
            ->assertOk();

        $entry = collect($response->json('data'))->firstWhere('slug', 'penawaran');

        $this->assertNotNull($entry, 'penawaran must be catalogued');
        $this->assertSame('crm/quotations', $entry['resource']);
        $this->assertSame('Penawaran', $entry['label']);
        $this->assertSame('id', $entry['idField']);
    }

    /**
     * Printing is reading. A caller who may not read a penawaran must not even
     * be told there is a button for one — the catalogue is what draws the
     * button, and a button that always answers 403 is a support ticket.
     */
    public function test_the_catalogue_hides_documents_the_caller_may_not_print(): void
    {
        $slugs = collect(
            $this->actingAs($this->userWithout('crm.view'))
                ->getJson('/api/core/print/forms')
                ->assertOk()
                ->json('data')
        )->pluck('slug');

        $this->assertNotContains('penawaran', $slugs->all());
    }

    /**
     * One lookup order, and it can only be one because the two key spaces never
     * overlap. FormPrintService::FORMS wins on a collision, which would silently
     * shadow a registry document — so the collision is refused here instead.
     */
    public function test_a_registry_key_never_collides_with_a_bespoke_form(): void
    {
        $bespoke = ['data-proyek', 'laporan-harian', 'laporan-mingguan', 'daftar-temuan',
            'izin-kerja', 'izin-lembur', 'izin-material'];

        $this->assertSame(
            [],
            array_values(array_intersect(array_keys(app(PrintableDocuments::class)->all()), $bespoke)),
        );
    }

    // ------------------------------------------------- the worked example

    public function test_a_registry_document_renders_the_house_sheet(): void
    {
        $html = $this->forms->html('penawaran', ['id' => $this->quotation()->id]);

        // The band, the title, the form code — the sheet is the house sheet,
        // not a second format that happens to print.
        $this->assertStringContainsString('KONTRAKTOR', $html);
        $this->assertStringContainsString('PT Nusantara Karya Integrasi', $html);
        $this->assertStringContainsString('SURAT PENAWARAN HARGA', $html);
        $this->assertStringContainsString('Form F/PN', $html);
        $this->assertStringContainsString('QTN/2026/VIII/0007', $html);
    }

    public function test_the_body_table_prints_every_line_and_its_totals(): void
    {
        $html = $this->forms->html('penawaran', ['id' => $this->quotation()->id]);

        $this->assertStringContainsString('Kamera IP fixed dome 4MP, termasuk bracket', $html);
        $this->assertStringContainsString('NVR 32 kanal dengan storage 24 TB', $html);
        // Formatted by the one formatter every house form shares, so a penawaran
        // cannot start writing rupiah differently from a laporan harian.
        $this->assertStringContainsString('250.000.000,00', $html);
        $this->assertStringContainsString('47.500.000,00', $html);
        $this->assertStringContainsString('321.900.000,00', $html);
    }

    /**
     * The whole reason the counterparty is declared rather than assumed: a
     * penawaran has no project, no SPK and no site. The band must still be four
     * boxes and must never print the word null.
     */
    public function test_a_document_with_no_project_still_prints_a_coherent_band(): void
    {
        $html = $this->forms->html('penawaran', ['id' => $this->quotation()->id]);

        $this->assertStringContainsString('PEMILIK', $html);
        $this->assertStringContainsString('PT Angkasa Pura I (Persero)', $html);
        $this->assertStringNotContainsString('null', $html);

        // No project means no contract arithmetic. Printing "HARI KE" on a
        // penawaran would be asserting a job that has not been won yet.
        $this->assertStringNotContainsString('HARI KE', $html);
        $this->assertStringNotContainsString('SISA HARI', $html);
    }

    /**
     * The failure this assertion exists for was real and silent: a caption read
     * as a dotted path resolves to nothing, so "Menyetujui," / "Subtotal" /
     * "Direktur" printed as EMPTY CELLS — a signature block of three unlabelled
     * rules and a totals column with no words beside the figures. Nothing threw,
     * nothing logged, and the sheet looked plausible until you read it.
     */
    public function test_captions_are_printed_as_written_not_read_off_the_record(): void
    {
        $html = $this->forms->html('penawaran', ['id' => $this->quotation()->id]);

        $this->assertStringContainsString('Menyetujui,', $html);
        $this->assertStringContainsString('Hormat kami,', $html);
        $this->assertStringContainsString('Direktur', $html);
        $this->assertStringContainsString('Subtotal', $html);
        $this->assertStringContainsString('TOTAL PENAWARAN', $html);
        // And a caption that really is a resolver still resolves: the rate is
        // stored per document and must never be a number typed into a template.
        $this->assertStringContainsString('PPN 11%', $html);
    }

    /**
     * A field the record does not carry is a ruled blank, never a guess.
     *
     * This is the ENGINE-level statement of the honesty rule, and the other 31
     * declarative documents inherit their confidence from it — so it is
     * asserted on the CELL. "fill-line" appears somewhere on nearly every house
     * sheet (the PEKERJAAN line alone rules itself when the entry declares no
     * subject), so a resolver that answered BERLAKU S/D with
     * `valid_until ?? created_at->addDays(30)` — a validity nobody agreed to,
     * printed over three signature rules — kept a bare
     * assertStringContainsString('fill-line') green.
     */
    public function test_an_unanswered_identity_line_is_a_ruled_blank(): void
    {
        $html = $this->forms->html('penawaran', ['id' => $this->quotation(['valid_until' => null])->id]);

        $this->assertMatchesRegularExpression($this->ruledIdentityCell('BERLAKU S/D'), $html);
    }

    /** And a stored date on the same line still prints, so the rule above is not "always blank". */
    public function test_a_recorded_validity_date_is_printed_on_that_line(): void
    {
        $html = $this->forms->html('penawaran', ['id' => $this->quotation(['valid_until' => '2026-09-30'])->id]);

        $this->assertMatchesRegularExpression($this->identityCell('BERLAKU S/D', '30 September 2026'), $html);
    }

    // ------------------------------------------------------ the generic path

    /**
     * Two declared tables must render as two tables, not one table with a
     * second heading row: the sheet's borders are what a signed form is read
     * by, and a merged table quietly re-parents every row.
     */
    public function test_two_declared_body_tables_render_as_two_tables(): void
    {
        $this->bindFixtureRegistry();

        $html = $this->forms->html('uji-dua-tabel', ['id' => $this->quotation()->id]);

        $this->assertStringContainsString('id="uji-a"', $html);
        $this->assertStringContainsString('id="uji-b"', $html);
        $this->assertStringContainsString('BARIS A1', $html);
        $this->assertStringContainsString('BARIS B1', $html);
    }

    /**
     * A table declared with minRows is a PAD: the rows the ERP actually has,
     * then RULED BLANKS up to the declared minimum. Never rows of zeros, and
     * never rows of repeated last values — a permit sheet with five identical
     * lines on it is a sheet somebody signs without reading.
     */
    public function test_a_pad_table_is_filled_out_with_ruled_blanks_not_values(): void
    {
        $this->bindFixtureRegistry();

        $html = $this->forms->html('uji-dua-tabel', ['id' => $this->quotation()->id]);

        // One recorded row plus two rules, and the recorded row still says what
        // it says.
        $this->assertSame(2, substr_count($html, '<div class="fill"></div>'));
        $this->assertSame(1, substr_count($html, 'BARIS B1'));
    }

    /**
     * A registry document that DOES hang off a project gets the same header
     * this codebase has already shipped — the same band, the same identity
     * block, the same day arithmetic — because a second implementation of
     * "hari ke" is a second answer to a question three parties sign against.
     */
    public function test_a_project_backed_registry_document_carries_the_house_identity_block(): void
    {
        $this->bindFixtureRegistry();

        $html = $this->forms->html('uji-proyek', ['id' => $this->project()->id]);

        $this->assertStringContainsString('NO. SPK / KONTRAK', $html);
        $this->assertStringContainsString('SPK/AP1/2025/XII/0142', $html);
        $this->assertStringContainsString('HARI KE', $html);
        $this->assertStringContainsString('PT Jaya CM', $html);
    }

    // ------------------------------------------------ the identity block

    /*
     * registryIdentity() is the method ALL 32 declarative documents go through
     * for their identity block, and the two things it learned last — a caption
     * that varies with the record, and the sheet's own date reaching the
     * resolvers — shipped tested only through the two documents that needed
     * them. Both are engine behaviour: the next entry that declares a 'label'
     * or an as-at line inherits it sight unseen. Exercised here on the fixture
     * registry, which is what it is for.
     */

    /**
     * A per-entry 'label' resolver replaces the CAPTION and nothing else.
     *
     * The array key stays the key — it is what the line IS in declaration
     * order, and the ordering of the block is read off it — so only the printed
     * caption moves, and the value beside it is resolved exactly as before.
     */
    public function test_an_identity_label_resolver_replaces_only_the_caption(): void
    {
        $this->bindFixtureRegistry();

        $html = $this->forms->html('uji-identitas', ['id' => $this->quotation()->id]);

        $this->assertMatchesRegularExpression($this->identityCell('KEPALA BARU', 'QTN/2026/VIII/0007'), $html);
        $this->assertStringNotContainsString('KUNCI DIGANTI', $html);
    }

    /**
     * A label resolver that answers nothing falls back to the declared key.
     *
     * The alternative is a value printed beside an EMPTY caption — a filled
     * cell on a signed sheet with nothing saying what it is — which is exactly
     * what reading a caption as a dotted path used to produce.
     */
    public function test_a_label_resolver_that_answers_nothing_keeps_the_declared_key(): void
    {
        $this->bindFixtureRegistry();

        $html = $this->forms->html('uji-identitas', ['id' => $this->quotation()->id]);

        $this->assertMatchesRegularExpression(
            $this->identityCell('KUNCI TETAP', 'Pengadaan dan Pemasangan Sistem CCTV Terminal 2'),
            $html,
        );
    }

    /** And a plain string key — every other line in the registry — is untouched. */
    public function test_a_plain_string_key_is_printed_as_the_caption(): void
    {
        $this->bindFixtureRegistry();

        $html = $this->forms->html('uji-identitas', ['id' => $this->quotation()->id]);

        $this->assertMatchesRegularExpression($this->identityCell('KUNCI POLOS', 'QTN/2026/VIII/0007'), $html);
    }

    /**
     * An identity resolver is handed the composed SHEET DATE as its second
     * argument.
     *
     * A line that answers "as at when?" — days remaining, days elapsed — must
     * answer it as at the date printed on the sheet, not as at the moment
     * somebody pressed the button. Without it a sheet printed with
     * ?tanggal=2026-01-01 headed itself "01 Januari 2026" and then counted from
     * today.
     */
    public function test_an_identity_resolver_is_handed_the_sheets_own_date(): void
    {
        $this->bindFixtureRegistry();

        $html = $this->forms->html('uji-identitas', [
            'id' => $this->quotation()->id,
            'date' => '2026-03-05',
        ]);

        $this->assertMatchesRegularExpression($this->identityCell('TANGGAL LEMBAR', '2026-03-05'), $html);
    }

    /**
     * And that date is the DOCUMENT's own date column when the entry declares
     * one, never the URL's.
     *
     * An invoice dated 12 Juli is dated 12 Juli whenever it is reprinted; a URL
     * that could re-date it would make every reprint a different document. The
     * fixture declares 'date' => 'valid_until', so the ?tanggal= below is
     * ignored — by the resolver as well as by the letter date, which is the
     * half that could have drifted apart.
     */
    public function test_the_documents_own_date_column_beats_the_url(): void
    {
        $this->bindFixtureRegistry();

        $html = $this->forms->html('uji-tanggal', [
            'id' => $this->quotation(['valid_until' => '2026-09-30'])->id,
            'date' => '2026-03-05',
        ]);

        $this->assertMatchesRegularExpression($this->identityCell('TANGGAL LEMBAR', '2026-09-30'), $html);
        $this->assertStringNotContainsString('2026-03-05', $html);
        // The letter date over the signature block is the same day.
        $this->assertStringContainsString('30 September 2026', $html);
    }

    // ------------------------------------------------------------- prose

    /**
     * A registry LETTER (T3.7): the `prose` spec's paragraphs print as
     * paragraphs between the identity block and the tables, handed the same
     * sheet date the identity resolvers get, and a paragraph the composer
     * did not write (null, empty, whitespace) is simply not there — a letter
     * has no ruled blank.
     */
    public function test_a_prose_spec_prints_its_paragraphs_before_the_tables_and_drops_the_blank_ones(): void
    {
        $this->bindFixtureRegistry();

        $html = $this->forms->html('uji-prosa', ['id' => $this->quotation()->id, 'date' => '2026-08-09']);

        $this->assertSame(2, substr_count($html, '<p class="alinea"'));
        $this->assertStringContainsString('>Dengan hormat,</p>', $html);
        $this->assertStringContainsString('>Alinea tentang QTN/2026/VIII/0007 tertanggal 2026-08-09.</p>', $html);
        $this->assertLessThan(
            strpos($html, 'BARIS SETELAH PROSA'),
            strpos($html, 'Dengan hormat,'),
            'the letter body comes before its table',
        );
    }

    /** And a document that declares no prose prints no paragraph at all. */
    public function test_a_document_without_prose_prints_no_paragraph(): void
    {
        $html = $this->forms->html('penawaran', ['id' => $this->quotation()->id]);

        $this->assertStringNotContainsString('class="alinea"', $html);
    }

    /**
     * One identity ROW — the caption and the value together, in the markup that
     * puts them in the same row of the block. Either half on its own says
     * nothing about whether they met in one cell.
     */
    private function identityCell(string $label, string $value): string
    {
        return '~>'.preg_quote($label, '~').'</td>\s*<td class="s">:</td>\s*<td class="v">\s*'
            .preg_quote($value, '~').'\s*</td>~';
    }

    /** The same row with a RULED BLANK where the value would be. */
    private function ruledIdentityCell(string $label): string
    {
        return $this->identityCell($label, '<span class="fill-line"></span>');
    }

    // ---------------------------------------------------------- the endpoint

    public function test_the_endpoint_serves_a_registry_document_as_printable_html(): void
    {
        $quotation = $this->quotation();

        $response = $this->actingAs($this->adminUser())
            ->get("/api/core/print/forms/penawaran/{$quotation->id}")
            ->assertOk();

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('SURAT PENAWARAN HARGA', $response->getContent());
    }

    public function test_printing_a_registry_document_needs_the_owning_modules_view(): void
    {
        $quotation = $this->quotation();

        $this->actingAs($this->userWithout('crm.view'))
            ->get("/api/core/print/forms/penawaran/{$quotation->id}")
            ->assertForbidden();
    }

    public function test_a_registry_document_for_a_missing_record_is_a_404(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/api/core/print/forms/penawaran/9999')
            ->assertNotFound();
    }

    // ------------------------------------------------------------- fixtures

    /**
     * A project that runs the whole of 2026, exactly as FormPrintTest builds
     * one, so the day arithmetic asserted above is the arithmetic already
     * shipped rather than a second copy of it.
     */
    private function project(): Project
    {
        $customer = $this->customer();

        $contract = Contract::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Pengembangan Bandar Udara Sultan Hasanudin',
            'scope_type' => 'construction',
            'value' => 48_500_000_000,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'warranty_months' => 12,
            'contract_number_customer' => 'SPK/AP1/2025/XII/0142',
            'sign_date' => '2025-12-18',
            'status' => 'approved',
        ]);

        return Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Pengembangan Bandar Udara Sultan Hasanudin - Makassar',
            'contract_id' => $contract->id,
            'customer_id' => $customer->id,
            'type' => 'construction',
            'status' => 'active',
            'city' => 'Makassar',
            'province' => 'Sulawesi Selatan',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'contract_value' => 48_500_000_000,
            'retention_pct' => 5.0,
            'warranty_months' => 12,
            'consultant_name' => 'PT Jaya CM',
        ]);
    }

    /**
     * Two extra documents, bound into the container the way DocumentImportTest
     * binds its own ImportableDocuments subclass — so the ENGINE is exercised
     * without shipping a document nobody asked for.
     */
    private function bindFixtureRegistry(): void
    {
        $this->app->bind(PrintableDocuments::class, fn () => new class extends PrintableDocuments
        {
            public function all(): array
            {
                return parent::all() + [
                    'uji-dua-tabel' => [
                        'resource' => 'crm/quotations',
                        'model' => Quotation::class,
                        'permission' => 'crm.view',
                        'label' => 'Uji Dua Tabel',
                        'formTitle' => 'UJI DUA TABEL',
                        'header' => ['kind' => 'customer', 'source' => 'customer'],
                        'identity' => ['NO. DOKUMEN' => 'code'],
                        'body' => [
                            [
                                'id' => 'uji-a',
                                'title' => 'TABEL A',
                                'rows' => fn (): array => [['nama' => 'BARIS A1']],
                                'columns' => [
                                    ['label' => 'NAMA', 'value' => 'nama'],
                                ],
                            ],
                            [
                                'id' => 'uji-b',
                                'title' => 'TABEL B',
                                'rows' => fn (): array => [['nama' => 'BARIS B1']],
                                'columns' => [
                                    ['label' => 'NAMA', 'value' => 'nama'],
                                ],
                                // A pad: one recorded row, two rules for the pen.
                                'minRows' => 3,
                            ],
                        ],
                    ],
                    'uji-proyek' => [
                        'resource' => 'projects/projects',
                        'model' => Project::class,
                        'permission' => 'prj.view',
                        'label' => 'Uji Proyek',
                        'formTitle' => 'UJI PROYEK',
                        'header' => ['kind' => 'project', 'source' => null],
                        'identity' => [],
                        'body' => [
                            [
                                'rows' => fn (): array => [['nama' => 'BARIS P1']],
                                'columns' => [['label' => 'NAMA', 'value' => 'nama']],
                            ],
                        ],
                    ],
                    // The four shapes registryIdentity() can be handed, on one
                    // sheet. No 'date' key, so this one honours the URL.
                    'uji-identitas' => [
                        'resource' => 'crm/quotations',
                        'model' => Quotation::class,
                        'permission' => 'crm.view',
                        'label' => 'Uji Identitas',
                        'formTitle' => 'UJI IDENTITAS',
                        'header' => ['kind' => 'customer', 'source' => 'customer'],
                        'identity' => [
                            'KUNCI POLOS' => 'code',
                            'KUNCI DIGANTI' => [
                                'label' => fn (Quotation $quotation): string => 'KEPALA BARU',
                                'value' => 'code',
                            ],
                            'KUNCI TETAP' => [
                                'label' => fn (Quotation $quotation): ?string => null,
                                'value' => 'title',
                            ],
                            'TANGGAL LEMBAR' => fn (Quotation $quotation, Carbon $date): string => $date->toDateString(),
                        ],
                    ],
                    // The same date line on a document that HAS a date column.
                    'uji-tanggal' => [
                        'resource' => 'crm/quotations',
                        'model' => Quotation::class,
                        'permission' => 'crm.view',
                        'label' => 'Uji Tanggal',
                        'formTitle' => 'UJI TANGGAL',
                        'header' => ['kind' => 'customer', 'source' => 'customer'],
                        'date' => 'valid_until',
                        'identity' => [
                            'TANGGAL LEMBAR' => fn (Quotation $quotation, Carbon $date): string => $date->toDateString(),
                        ],
                    ],
                    // A LETTER (T3.7): prose paragraphs before the tables,
                    // handed the record and the sheet's date; blanks dropped.
                    'uji-prosa' => [
                        'resource' => 'crm/quotations',
                        'model' => Quotation::class,
                        'permission' => 'crm.view',
                        'label' => 'Uji Prosa',
                        'formTitle' => 'UJI PROSA',
                        'header' => ['kind' => 'customer', 'source' => 'customer'],
                        'identity' => ['NO. DOKUMEN' => 'code'],
                        'prose' => fn (Quotation $quotation, Carbon $date): array => [
                            'Dengan hormat,',
                            "Alinea tentang {$quotation->code} tertanggal {$date->toDateString()}.",
                            '',
                            null,
                            '   ',
                        ],
                        'body' => [
                            [
                                'id' => 'uji-setelah-prosa',
                                'rows' => fn (): array => [['nama' => 'BARIS SETELAH PROSA']],
                                'columns' => [['label' => 'NAMA', 'value' => 'nama']],
                            ],
                        ],
                    ],
                ];
            }
        });
    }
}
