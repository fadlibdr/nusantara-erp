<?php

namespace Tests\Feature\Assets;

use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Models\Maintenance;
use Modules\Assets\Services\AssetDisposalService;
use Modules\Assets\Services\DeploymentService;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Formulir rumah untuk Aset — kartu aset dan berita acara mobilisasi.
 *
 * TWO SHEETS, AND THE INTERESTING ONE IS THE CARD, because a kartu aset is the
 * only house form in this ERP whose figures MOVE UNDER THE READER. An asset's
 * accumulated_depreciation and book_value are today's, rewritten by every
 * posted depreciation run, and ast_assets keeps no history of what they were
 * in June. So the card is dated by the PRINTER — a ?tanggal= in the URL cannot
 * re-date it into a claim about a month whose figures nobody kept — and it
 * says which run it is standing on.
 *
 * The other decisions:
 *
 *   PENYUSUTAN PER BULAN comes from Asset::monthlyDepreciation(), the same
 *   formula DepreciationService posts with. A second straight-line division
 *   written for the paper would disagree with the ledger the first time
 *   salvage_value or useful_life_months changed. The guard around it is the
 *   registry's, and it mirrors both of DepreciationService's conditions.
 *
 *   THE DISPOSAL FACTS RIDE IN THE CATATAN BLOCK, not in identity lines. An
 *   identity line prints its label whether or not it has a value, so a live
 *   excavator would carry "NILAI PELEPASAN : ......" — a ruled line inviting
 *   somebody to write a sale price onto the card of an asset nobody sold.
 *
 *   KONDISI ALAT ON THE MOBILISATION BERITA ACARA IS A PAD. Nothing in
 *   ast_deployments records condition, hours on the clock, fuel or
 *   attachments; the two parties walk round the machine and write on the
 *   sheet. Ruled rows, never a row of "Baik".
 *
 *   THE INTERNAL DAILY RATE IS NULLABLE AND MEANS IT. A deployment with no
 *   rate is plant lent to a job with no internal charge agreed, which is not
 *   the same as a rate of nothing — and DeploymentService::returnDeployment
 *   charges the project off exactly that column.
 */
class AssetPrintTest extends ErpTestCase
{
    private FormPrintService $forms;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
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

        $this->project = Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Pengembangan Bandar Udara Sultan Hasanudin - Makassar',
            'type' => 'construction',
            'status' => 'active',
            'city' => 'Makassar',
            'province' => 'Sulawesi Selatan',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'consultant_name' => 'PT Ciriajasa Cipta Mandiri',
        ]);
    }

    // ------------------------------------------------------------- fixtures

    private function category(): AssetCategory
    {
        // The three account hints are the ones AssetCategorySeeder ships for
        // Alat Berat; AssetDisposalService refuses to derecognise a category
        // without them rather than guessing an asset class.
        return AssetCategory::query()->firstOrCreate(
            ['code' => 'CAT-ALAT'],
            [
                'name' => 'Alat Berat',
                'useful_life_months_default' => 96,
                'depreciation_account_hint' => '6-3100',
                'accum_account_hint' => '1-2410',
                'asset_account_hint' => '1-2400',
            ],
        );
    }

    /**
     * Rp 960.000.000 over 96 months = Rp 10.000.000 a month; 12 months already
     * posted leaves a book value of Rp 840.000.000.
     */
    private function asset(array $attributes = []): Asset
    {
        return Asset::query()->create(array_merge([
            'code' => 'AST-0001',
            'name' => 'Excavator Komatsu PC200-8',
            'category_id' => $this->category()->id,
            'brand' => 'Komatsu',
            'model' => 'PC200-8',
            'serial_no' => 'KMTPC200-J19875',
            'acquisition_date' => '2025-01-01',
            'depreciation_start_date' => '2025-01-01',
            'acquisition_cost' => 960_000_000,
            'salvage_value' => 0,
            'useful_life_months' => 96,
            'accumulated_depreciation' => 120_000_000,
            'book_value' => 840_000_000,
            'status' => 'available',
        ], $attributes));
    }

    private function deployment(Asset $asset, array $attributes = []): Deployment
    {
        return app(DeploymentService::class)->deploy($asset, array_merge([
            'project_id' => (int) $this->project->id,
            'deployed_from' => '2026-03-02',
            'planned_until' => '2026-08-31',
            'daily_rate_internal' => 2_500_000,
            'notes' => 'Mobilisasi untuk pekerjaan galian apron sisi timur.',
        ], $attributes));
    }

    private function maintenance(Asset $asset): Maintenance
    {
        $vendor = Vendor::query()->create([
            'name' => 'CV Teknik Alat Berat',
            'classification' => 'jasa',
            'is_pkp' => false,
            'payment_term_days' => 14,
            'status' => 'active',
        ]);

        return Maintenance::query()->create([
            'asset_id' => $asset->id,
            'maintenance_date' => '2026-02-14',
            'maintenance_type' => 'service_rutin',
            'vendor_id' => $vendor->id,
            'cost' => 18_500_000,
            'description' => 'Ganti oli hidrolik, filter dan track adjuster.',
            'next_due_date' => '2026-08-14',
        ]);
    }

    private function fills(string $html): int
    {
        return substr_count($html, '<div class="fill"></div>');
    }

    /** Today, as the sheet spells a date. */
    private function todayLabel(): string
    {
        $months = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];

        return now()->format('d').' '.$months[(int) now()->format('n')].' '.now()->format('Y');
    }

    // ---------------------------------------------------------- kartu aset

    public function test_the_asset_card_prints_its_identity_cost_and_book_value(): void
    {
        $asset = $this->asset();

        $html = $this->forms->html('kartu-aset', ['id' => $asset->id]);

        $this->assertStringContainsString('KARTU ASET', $html);
        $this->assertStringContainsString('Form F/KA', $html);
        $this->assertStringContainsString('AST-0001', $html);
        $this->assertStringContainsString('Excavator Komatsu PC200-8', $html);
        $this->assertStringContainsString('KMTPC200-J19875', $html);
        $this->assertStringContainsString('Alat Berat', $html);
        $this->assertStringContainsString('960.000.000,00', $html);
        $this->assertStringContainsString('120.000.000,00', $html);
        $this->assertStringContainsString('840.000.000,00', $html);
        // Asset::monthlyDepreciation(), the figure DepreciationService posts.
        $this->assertStringContainsString('10.000.000,00', $html);
        $this->assertStringContainsString('96 bulan', $html);
        $this->assertStringNotContainsString('null', $html);
    }

    public function test_the_asset_card_carries_both_history_tables(): void
    {
        $asset = $this->asset();
        $deployment = $this->deployment($asset);
        $this->maintenance($asset);

        $html = $this->forms->html('kartu-aset', ['id' => $asset->refresh()->id]);

        $this->assertStringContainsString('RIWAYAT MOBILISASI', $html);
        $this->assertStringContainsString($deployment->code, $html);
        $this->assertStringContainsString('Pengembangan Bandar Udara Sultan Hasanudin', $html);
        $this->assertStringContainsString('2 Maret 2026', $html);
        $this->assertStringContainsString('2.500.000,00', $html);

        $this->assertStringContainsString('RIWAYAT PEMELIHARAAN', $html);
        $this->assertStringContainsString('Service Rutin', $html);
        $this->assertStringContainsString('CV Teknik Alat Berat', $html);
        $this->assertStringContainsString('18.500.000,00', $html);
        $this->assertStringContainsString('14 Agustus 2026', $html);
    }

    /**
     * An asset nobody has moved or serviced has no history, and the two tables
     * say so in words. A pad of ruled rows here would invite a mobilisation to
     * be written onto a card by hand, outside the register that charges the
     * project for it.
     */
    public function test_an_asset_with_no_history_says_so_rather_than_ruling_rows(): void
    {
        $html = $this->forms->html('kartu-aset', ['id' => $this->asset()->id]);

        $this->assertStringContainsString('Belum ada mobilisasi tercatat', $html);
        $this->assertStringContainsString('Belum ada pemeliharaan tercatat', $html);
        $this->assertSame(0, $this->fills($html));
    }

    /**
     * accumulated_depreciation and book_value are TODAY'S, rewritten by every
     * posted run, and nothing keeps what they were last quarter. A URL date
     * must not be able to head the card with a month whose figures the card
     * cannot show.
     */
    public function test_the_asset_card_is_dated_by_the_printer_not_by_the_url(): void
    {
        $html = $this->forms->html('kartu-aset', [
            'id' => $this->asset()->id,
            'date' => '2025-06-30',
        ]);

        $this->assertStringNotContainsString('30 Juni 2025', $html);
        // Not merely "not June": the card states the day it was printed, which
        // is the only day its accumulated depreciation belongs to.
        $this->assertStringContainsString('NILAI PER TANGGAL', $html);
        $this->assertStringContainsString($this->todayLabel(), $html);
    }

    /**
     * The disposal facts print only on a card that has them, and they print in
     * the catatan block rather than as identity lines — see the class docblock.
     */
    public function test_a_disposed_asset_states_its_disposal_and_a_live_one_says_nothing(): void
    {
        $live = $this->forms->html('kartu-aset', ['id' => $this->asset()->id]);
        $this->assertStringNotContainsString('Pelepasan aset', $live);

        $asset = $this->asset(['code' => 'AST-0002', 'name' => 'Dump Truck Hino 500']);
        app(AssetDisposalService::class)->dispose($asset, [
            'disposal_date' => '2026-05-12',
            'disposal_value' => 250_000_000,
            'reason' => 'Dijual ke PT Rekayasa Alat, unit sudah tidak ekonomis.',
        ]);

        $html = $this->forms->html('kartu-aset', ['id' => $asset->refresh()->id]);

        $this->assertStringContainsString('Pelepasan aset', $html);
        $this->assertStringContainsString('12 Mei 2026', $html);
        $this->assertStringContainsString('250.000.000,00', $html);
        $this->assertStringContainsString('Dijual ke PT Rekayasa Alat', $html);
        // The colon belongs to the facts that follow it — see the pair of
        // tests below, which is where the sentence is asserted whole.
        $this->assertStringContainsString(
            'Pelepasan aset : 12 Mei 2026, nilai Rp 250.000.000,00.',
            $html,
        );
    }

    /**
     * A disposal row carrying a REASON and neither date nor value.
     *
     * The sentence composed " : ." — "Pelepasan aset : . Dijual ke pihak
     * ketiga" — in the catatan block of a card three people sign, which reads
     * as a cell the printer failed to fill rather than as a fact the register
     * does not hold.
     *
     * The row is written STRAIGHT ONTO THE TABLE because no request can
     * produce it: AssetDisposeRequest demands the three together and
     * AssetUpdateRequest accepts none of them. The three columns are
     * independently nullable in ast_assets all the same — the reason arrived
     * in a later migration, nullable, over rows that already had a date and a
     * value — and a seeder, an import or a console fix writes them without a
     * FormRequest anywhere in sight. A sentence that only reads correctly
     * while a validator somewhere else keeps holding is not a sentence to
     * print over three signatures.
     */
    public function test_a_disposal_with_no_date_and_no_value_still_reads_as_a_sentence(): void
    {
        $asset = $this->asset(['code' => 'AST-0003', 'name' => 'Concrete Mixer Truck']);

        $asset->forceFill([
            'disposal_reason' => 'Diserahkan ke pihak ketiga, dokumen menyusul.',
            'disposal_date' => null,
            'disposal_value' => null,
        ])->save();

        $html = $this->forms->html('kartu-aset', ['id' => $asset->refresh()->id]);

        $this->assertStringContainsString('Pelepasan aset.', $html);
        $this->assertStringNotContainsString('Pelepasan aset : .', $html);
        $this->assertStringContainsString('Diserahkan ke pihak ketiga', $html);
    }

    /**
     * And the colon comes back the moment there IS something to follow it,
     * on EACH populated branch — otherwise the fix above regresses into a
     * sentence that has simply stopped stating the facts.
     */
    public function test_a_disposal_that_records_one_fact_still_states_it_after_the_colon(): void
    {
        $dated = $this->asset(['code' => 'AST-0004', 'name' => 'Vibro Roller']);
        $dated->forceFill([
            'disposal_date' => '2026-05-12',
            'disposal_value' => null,
            'disposal_reason' => null,
        ])->save();

        $this->assertStringContainsString(
            'Pelepasan aset : 12 Mei 2026.',
            $this->forms->html('kartu-aset', ['id' => $dated->refresh()->id]),
        );

        $valued = $this->asset(['code' => 'AST-0005', 'name' => 'Genset 150 kVA']);
        // A disposal at 0 is a real outcome — scrapped, or handed over for
        // nothing — and states itself rather than being left out.
        $valued->forceFill([
            'disposal_date' => null,
            'disposal_value' => 0,
            'disposal_reason' => null,
        ])->save();

        $this->assertStringContainsString(
            'Pelepasan aset : nilai Rp 0,00.',
            $this->forms->html('kartu-aset', ['id' => $valued->refresh()->id]),
        );
    }

    // ------------------------------------------- berita acara mobilisasi

    public function test_the_mobilisation_sheet_carries_the_project_band_and_the_rate(): void
    {
        $deployment = $this->deployment($this->asset());

        $html = $this->forms->html('berita-acara-mobilisasi', ['id' => $deployment->id]);

        $this->assertStringContainsString('BERITA ACARA MOBILISASI', $html);
        $this->assertStringContainsString('Form F/BAM', $html);
        $this->assertStringContainsString($deployment->code, $html);
        // A mobilisation is a site document: four-party band, contract days.
        $this->assertStringContainsString('KONSULTAN MK', $html);
        $this->assertStringContainsString('PT Ciriajasa Cipta Mandiri', $html);
        $this->assertStringContainsString('HARI KE', $html);
        $this->assertStringContainsString('Excavator Komatsu PC200-8', $html);
        $this->assertStringContainsString('KMTPC200-J19875', $html);
        $this->assertStringContainsString('2 Maret 2026', $html);
        $this->assertStringContainsString('2.500.000,00', $html);
        $this->assertStringContainsString('galian apron sisi timur', $html);
    }

    /**
     * The condition pad. Nothing in ast_deployments records what the machine
     * looked like when it changed hands, and that is exactly what the two
     * signatures on this sheet are about.
     */
    public function test_the_mobilisation_sheet_rules_the_condition_rows(): void
    {
        $deployment = $this->deployment($this->asset());

        $html = $this->forms->html('berita-acara-mobilisasi', ['id' => $deployment->id]);

        $this->assertStringContainsString('KONDISI ALAT', $html);
        // Six ruled rows of four columns, for the pen.
        $this->assertSame(24, $this->fills($html));
        $this->assertStringContainsString('Menyerahkan,', $html);
        $this->assertStringContainsString('Menerima,', $html);
    }

    /**
     * daily_rate_internal is nullable and a null means no internal charge was
     * agreed — not a charge of nothing. Printed as "Rp 0,00" it would tell a
     * project manager the plant is free.
     */
    public function test_a_deployment_without_an_agreed_rate_rules_the_rate_line(): void
    {
        $deployment = $this->deployment($this->asset(), ['daily_rate_internal' => null]);

        $html = $this->forms->html('berita-acara-mobilisasi', ['id' => $deployment->id]);

        $this->assertStringContainsString('TARIF HARIAN INTERNAL', $html);
        $this->assertStringNotContainsString('Rp 0,00', $html);
    }

    // ------------------------------------------------------------ endpoint

    public function test_both_asset_documents_are_catalogued_for_their_resource(): void
    {
        $catalogue = collect(
            $this->actingAs($this->adminUser())
                ->getJson('/api/core/print/forms')
                ->assertOk()
                ->json('data')
        )->keyBy('slug');

        $this->assertSame('assets/assets', $catalogue['kartu-aset']['resource']);
        $this->assertSame('assets/deployments', $catalogue['berita-acara-mobilisasi']['resource']);
    }

    public function test_printing_an_asset_document_needs_the_modules_view(): void
    {
        $asset = $this->asset();
        $user = $this->adminUser();
        $user->roles->first()->revokePermissionTo('ast.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->refresh())
            ->get("/api/core/print/forms/kartu-aset/{$asset->id}")
            ->assertForbidden();
    }

    /**
     * An asset nobody is depreciating does not print a monthly charge, and
     * DepreciationService selects on TWO conditions, not one:
     * whereNotNull('depreciation_start_date') AND useful_life_months > 0.
     * Asset::monthlyDepreciation() mirrors neither — with no start date it
     * returns (cost - residu) / umur, and with umur 0 it returns 0.0 — so the
     * card printed "MULAI PENYUSUTAN : ......" ruled directly above
     * "PENYUSUTAN PER BULAN : Rp 10.000.000,00" in the first case, and
     * "Rp 0,00" in the second: a computed sentinel for "no useful life
     * recorded", printed as a monthly charge of nothing. Both sit above an
     * akumulasi of 0,00 that will never move, and one filled figure among
     * ruled neighbours reads as a charge somebody is making.
     */
    public function test_an_asset_with_no_depreciation_start_rules_its_monthly_charge(): void
    {
        $asset = $this->asset([
            'code' => 'AST-0009',
            'depreciation_start_date' => null,
            'accumulated_depreciation' => 0,
            'book_value' => 960_000_000,
        ]);

        $html = $this->forms->html('kartu-aset', ['id' => $asset->id]);

        // 960.000.000 / 96 = 10.000.000 a month, and it must not appear.
        $this->assertStringContainsString('PENYUSUTAN PER BULAN', $html);
        $this->assertStringNotContainsString('10.000.000,00', $html);
        // The cost itself is a fact and still prints.
        $this->assertStringContainsString('960.000.000,00', $html);
    }

    /**
     * The second condition, and the sentinel it stops printing as a charge.
     *
     * Asserted on the CELL. "Rp 0,00" is absent from nowhere on this sheet: an
     * asset with no residual value carries NILAI RESIDU : Rp 0,00 two lines up,
     * and that zero is a STORED FACT and prints. The zero this test refuses is
     * a COMPUTED one — monthlyDepreciation() dividing by a useful life of
     * nothing — on the line that says what the company charges each month.
     */
    public function test_an_asset_with_no_useful_life_rules_its_monthly_charge(): void
    {
        $asset = $this->asset(['code' => 'AST-0010', 'useful_life_months' => 0]);

        $html = $this->forms->html('kartu-aset', ['id' => $asset->id]);

        $this->assertMatchesRegularExpression($this->ruledIdentityCell('PENYUSUTAN PER BULAN'), $html);
        // The stored zero beside it is untouched — 0 is an answer.
        $this->assertMatchesRegularExpression($this->identityCell('NILAI RESIDU', 'Rp 0,00'), $html);
        // And the column that explains the ruled line prints as it stands.
        $this->assertMatchesRegularExpression($this->identityCell('UMUR MANFAAT', '0 bulan'), $html);
    }

    /** One identity ROW, label and value together, in the block's own markup. */
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

    /** And an asset that IS being depreciated still states the charge. */
    public function test_a_depreciating_asset_still_prints_its_monthly_charge(): void
    {
        $html = $this->forms->html('kartu-aset', ['id' => $this->asset()->id]);

        $this->assertStringContainsString('10.000.000,00', $html);
    }

    // ------------------------------------------------- kartu aset sewa (P5)

    /**
     * A rented machine, the shape AssetController::store writes for
     * ownership=rented: no purchase facts at all, book value NULL — the
     * machine is the lessor's and was never on our balance sheet.
     */
    private function rentedAsset(array $attributes = []): Asset
    {
        $lessor = Vendor::query()->create([
            'name' => 'PT Alat Berat Nusantara',
            'classification' => 'jasa',
            'vendor_type' => 'rental',
            'is_pkp' => true,
            'status' => 'active',
        ]);

        return Asset::query()->create(array_merge([
            'code' => 'AST-0002',
            'name' => 'Excavator Hitachi ZX200 (sewa)',
            'category_id' => $this->category()->id,
            'ownership' => 'rented',
            'vendor_id' => $lessor->id,
            'rental_rate' => 350_000,
            'rate_basis' => 'per_jam',
            'rental_start' => '2026-06-01',
            'rental_end' => '2026-12-31',
            'acquisition_date' => null,
            'acquisition_cost' => null,
            'salvage_value' => 0,
            'useful_life_months' => 0,
            'depreciation_start_date' => null,
            'accumulated_depreciation' => 0,
            'book_value' => null,
            'status' => 'available',
        ], $attributes));
    }

    /**
     * P5 — THE HONESTY RULE ON THE BOOK-VALUE CELL. A rented machine's
     * book_value is NULL and the card rules it: "Rp 0,00" there would be a
     * balance-sheet claim about a machine that was never on our balance
     * sheet. Same for the purchase facts — the machine was never bought.
     */
    public function test_a_rented_asset_rules_its_book_value_and_purchase_lines(): void
    {
        $html = $this->forms->html('kartu-aset', ['id' => $this->rentedAsset()->id]);

        $this->assertMatchesRegularExpression($this->ruledIdentityCell('NILAI BUKU'), $html);
        $this->assertMatchesRegularExpression($this->ruledIdentityCell('HARGA PEROLEHAN'), $html);
        $this->assertMatchesRegularExpression($this->ruledIdentityCell('TANGGAL PEROLEHAN'), $html);
        $this->assertMatchesRegularExpression($this->ruledIdentityCell('PENYUSUTAN PER BULAN'), $html);
        $this->assertStringNotContainsString('null', $html);
    }

    /** The card says which kind of machine this is, off the stored enum. */
    public function test_the_card_states_ownership_for_both_kinds(): void
    {
        $rented = $this->forms->html('kartu-aset', ['id' => $this->rentedAsset()->id]);
        $this->assertMatchesRegularExpression($this->identityCell('KEPEMILIKAN', 'Sewa'), $rented);

        $owned = $this->forms->html('kartu-aset', ['id' => $this->asset()->id]);
        $this->assertMatchesRegularExpression($this->identityCell('KEPEMILIKAN', 'Milik sendiri'), $owned);
    }

    /**
     * The rental facts ride in the catatan block, the same reasoning as the
     * disposal facts: identity lines print their label whether or not they
     * have a value, so putting VENDOR RENTAL / TARIF SEWA there would leave
     * every OWNED card carrying ruled lines inviting a lessor to be written
     * onto a machine the company owns.
     */
    public function test_the_rental_facts_ride_in_the_catatan_block(): void
    {
        $html = $this->forms->html('kartu-aset', ['id' => $this->rentedAsset()->id]);

        $this->assertStringContainsString('PT Alat Berat Nusantara', $html);
        $this->assertStringContainsString('350.000,00', $html);
        $this->assertStringContainsString('per jam', $html);
        $this->assertStringContainsString('01 Juni 2026', $html);
        $this->assertStringContainsString('31 Desember 2026', $html);
    }

    /** ...and an owned card says nothing about a rental. */
    public function test_an_owned_card_says_nothing_about_a_rental(): void
    {
        $html = $this->forms->html('kartu-aset', ['id' => $this->asset()->id]);

        $this->assertStringNotContainsString('Aset sewa', $html);
        $this->assertStringNotContainsString('VENDOR RENTAL', $html);
    }

    /**
     * A rental with no stated period still composes a sentence, not a stray
     * " : ." — the same defensive wording the disposal sentence carries, and
     * for the same reason: seeders and imports write columns without passing
     * a FormRequest.
     */
    public function test_a_rental_with_no_period_still_reads_as_a_sentence(): void
    {
        $html = $this->forms->html('kartu-aset', ['id' => $this->rentedAsset([
            'rental_start' => null,
            'rental_end' => null,
        ])->id]);

        $this->assertStringContainsString('PT Alat Berat Nusantara', $html);
        $this->assertStringNotContainsString(' : .', $html);
        $this->assertStringNotContainsString('null', $html);
    }
}
