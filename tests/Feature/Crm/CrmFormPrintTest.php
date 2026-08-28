<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Guarantee;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Models\QuotationItem;
use Modules\Crm\Services\ContractChangeOrderService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * Formulir rumah untuk modul CRM — penawaran, ringkasan kontrak, berita acara
 * tambah-kurang, register jaminan.
 *
 * Declared in Modules\Core\Support\PrintableDocuments and rendered by the
 * generic sheet; nothing per-document is written in Blade or in a composer
 * method. What has to be tested per document is therefore not "does it render"
 * but WHICH CELLS ARE FILLED AND WHICH ARE RULED — the declarative path makes a
 * plausible-looking default one keystroke away, and these four sheets are
 * signed by the customer.
 *
 * The berita acara is the case that earns most of this file. crm_contracts.value
 * moves only when a change order is APPROVED, so "nilai kontrak sesudah" is a
 * fact for an approved amendment and a proposal for a draft one. Printing the
 * same arithmetic in both states would put a contract value nobody has agreed to
 * on a sheet three parties sign.
 */
class CrmFormPrintTest extends ErpTestCase
{
    /** Every sisa-hari figure below is counted from this day. */
    private const TODAY = '2026-08-09';

    private FormPrintService $forms;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------- fixtures

    private function customer(): Customer
    {
        return Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'billing_address' => 'Jl. Jenderal Sudirman Kav. 52-53, Jakarta Selatan',
            'is_pkp' => true,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
    }

    /**
     * A signed contract worth Rp 1 miliar whose termin schedule deliberately
     * covers only Rp 750 juta — the gap is what the printed sheet has to make
     * visible rather than paper over.
     */
    private function contract(): Contract
    {
        $contract = Contract::query()->create([
            'code' => 'CTR/2026/I/0001',
            'customer_id' => $this->customer()->id,
            'contract_number_customer' => 'SPK/GSP/2026/017',
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)',
            'scope_type' => 'construction',
            'value' => 1_000_000_000,
            'ppn_rate' => 11.0,
            'ppn_amount' => 110_000_000,
            'total_with_ppn' => 1_110_000_000,
            'sign_date' => '2026-01-15',
            'start_date' => '2026-02-01',
            'end_date' => '2026-12-31',
            'retention_pct' => 5.0,
            'warranty_months' => 12,
            'status' => 'approved',
        ]);

        ContractTermin::query()->create([
            'contract_id' => $contract->id,
            'termin_no' => 1,
            'name' => 'DP 20%',
            'percent' => 20,
            'amount' => 200_000_000,
            'billing_condition' => 'Setelah SPK ditandatangani dan jaminan uang muka diserahkan',
            'due_date' => '2026-02-10',
            'billed_at' => '2026-02-12',
        ]);

        // A PLANNED date and no billed_at — the discriminating row for
        // test_an_unbilled_termin_leaves_its_billing_date_ruled. Without a
        // due_date here every unbilled row's two date cells are ruled for the
        // same reason, and a TGL. DITAGIH that fell back to the planned date
        // would print exactly the same sheet.
        ContractTermin::query()->create([
            'contract_id' => $contract->id,
            'termin_no' => 2,
            'name' => 'Progres 50%',
            'percent' => 50,
            'amount' => 500_000_000,
            'billing_condition' => 'Progres fisik 50% disetujui MK',
            'due_date' => '2026-07-10',
        ]);

        ContractTermin::query()->create([
            'contract_id' => $contract->id,
            'termin_no' => 3,
            'name' => 'Retensi 5%',
            'percent' => 5,
            'amount' => 50_000_000,
            'is_retention' => true,
        ]);

        return $contract->fresh();
    }

    private function project(Contract $contract): Project
    {
        return Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Gedung Kantor Graha Sentosa',
            'contract_id' => $contract->id,
            'customer_id' => $contract->customer_id,
            'type' => 'construction',
            'status' => 'active',
            'city' => 'Jakarta Selatan',
            'province' => 'DKI Jakarta',
            'start_date' => '2026-02-01',
            'end_date' => '2026-12-31',
            'contract_value' => 1_000_000_000,
            'retention_pct' => 5.0,
            'warranty_months' => 12,
            'consultant_name' => 'PT Jaya CM',
        ]);
    }

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

        return $quotation->fresh();
    }

    private function changeOrder(Contract $contract, float $change = 200_000_000): ContractChangeOrder
    {
        return app(ContractChangeOrderService::class)->create([
            'contract_id' => $contract->id,
            'change_date' => '2026-06-01',
            'title' => 'Penambahan pekerjaan ME lantai 9',
            'description' => "Penambahan instalasi ME lantai 9 sesuai permintaan pemilik.\nGambar revisi ME-09 rev.2.",
            'reason' => 'permintaan_pelanggan',
            'change_type' => 'tambah_kurang',
            'value_change' => $change,
            'customer_ref' => 'CCO-GSP-002',
        ]);
    }

    /**
     * An addendum waktu (P0-B) on the same contract: +14 hari on an end date
     * of 31 Desember 2026, so the approved date is 14 Januari 2027.
     */
    private function timeAddendum(Contract $contract, int $days = 14): ContractChangeOrder
    {
        return app(ContractChangeOrderService::class)->create([
            'contract_id' => $contract->id,
            'change_date' => '2026-06-01',
            'title' => 'Perpanjangan waktu — curah hujan ekstrem',
            'description' => 'Perpanjangan waktu pelaksanaan akibat curah hujan ekstrem.',
            'reason' => 'kondisi_lapangan',
            'change_type' => 'waktu',
            'days_change' => $days,
            'value_change' => 0,
            'customer_ref' => 'CCO-GSP-003',
        ]);
    }

    /** Maker-checker: the change order is approved by somebody else. */
    private function approve(ContractChangeOrder $order): ContractChangeOrder
    {
        $order->submit($this->person('maker@test.local'));

        return app(ContractChangeOrderService::class)->approve($order->refresh(), $this->person('checker@test.local'));
    }

    private function person(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- catalogue

    public function test_the_catalogue_carries_the_four_crm_documents(): void
    {
        $catalogue = collect(
            $this->actingAs($this->adminUser())
                ->getJson('/api/core/print/forms')
                ->assertOk()
                ->json('data')
        )->keyBy('slug');

        $this->assertSame('crm/quotations', $catalogue['penawaran']['resource'] ?? null);
        $this->assertSame('crm/contracts', $catalogue['kontrak-ringkas']['resource'] ?? null);
        $this->assertSame('crm/contract-change-orders', $catalogue['berita-acara-cco']['resource'] ?? null);
        $this->assertSame('crm/guarantees', $catalogue['register-jaminan']['resource'] ?? null);
    }

    // ------------------------------------------------------------ penawaran

    /**
     * Terbilang is not a second opinion about the total — it is the stored
     * total, spelled. A surat penawaran without it is refused by half the
     * bagian pengadaan this company sells to.
     */
    public function test_the_penawaran_spells_its_stored_total_in_words(): void
    {
        $html = $this->forms->html('penawaran', ['id' => $this->quotation()->id]);

        $this->assertStringContainsString('TERBILANG', $html);
        $this->assertStringContainsString(
            'Tiga ratus dua puluh satu juta sembilan ratus ribu rupiah',
            $html,
        );
    }

    /**
     * crm_quotations records no terms of sale — not a column, not a table. The
     * block is therefore RULED, exactly as the owner's own letter leaves it, and
     * on no account filled with a plausible house default: "pembayaran 30 hari"
     * printed under our own letterhead is an offer nobody made.
     */
    public function test_the_penawaran_rules_the_terms_block_rather_than_inventing_terms(): void
    {
        $html = $this->forms->html('penawaran', ['id' => $this->quotation()->id]);

        $this->assertStringContainsString('SYARAT', $html);
        $this->assertStringNotContainsString('30 hari', $html);
        $this->assertStringContainsString('<div class="fill"></div>', $html);
    }

    /**
     * The identity block carries NO date, and that is the decision.
     *
     * crm_quotations has no quotation_date column, so the only candidate was
     * created_at — the row's insert timestamp. Under a caption asserting the
     * day the offer was raised that produced the shipped demo's
     * QTN/2026/I/0001: "TANGGAL PENAWARAN : 26 Juli 2026" directly above
     * "BERLAKU S/D : 15 Februari 2026", an offer raised five months after it
     * lapsed. The letter date stays where a letter's date belongs — above the
     * third signature column, in the conventional Indonesian place — and
     * claims only when the sheet was drawn up.
     */
    public function test_the_penawaran_states_no_offer_date_it_does_not_record(): void
    {
        $quotation = $this->quotation([
            'created_at' => '2026-08-01 09:15:00',
            'valid_until' => '2026-02-15',
        ]);

        $html = $this->forms->html('penawaran', ['id' => $quotation->id]);

        $this->assertStringNotContainsString('TANGGAL PENAWARAN', $html);
        // The one place a date belongs on a letter, and it is still there.
        $this->assertStringContainsString('Jakarta Timur, 01 Agustus 2026', $html);
        // BERLAKU S/D is a stored column and still prints.
        $this->assertStringContainsString('15 Februari 2026', $html);
    }

    /**
     * A customer soft-deleted since the offer was sent still heads the offer.
     *
     * The band of a penawaran IS the customer — KEPADA, and the four-party
     * letterhead — so losing the relation leaves a signed offer addressed to
     * nobody, with a total of 321.900.000,00 under it. A lead that went cold
     * and was archived is the ordinary way this happens, and the reprint is
     * usually somebody looking up what we quoted.
     */
    public function test_a_penawaran_to_an_archived_customer_still_names_the_customer(): void
    {
        $quotation = $this->quotation();

        $quotation->customer->delete();

        $html = $this->forms->html('penawaran', ['id' => $quotation->id]);

        $this->assertStringContainsString('PT Graha Sentosa Propertindo', $html);
        $this->assertStringContainsString('321.900.000,00', $html);
    }

    /**
     * And the same customer, archived, still heads the CONTRACT summary and
     * still names the party who signs it.
     *
     * The first signature column of a ringkasan kontrak is declared
     * `'party' => 'customer.name'` — the pemberi kerja signs under their own
     * name — so a lost relation empties the letterhead, the KEPADA line AND
     * the rule a person puts their signature on, over a schedule totalling
     * Rp 750.000.000,00.
     */
    public function test_an_archived_customer_still_heads_and_signs_the_contract_summary(): void
    {
        $contract = $this->contract();

        $contract->customer->delete();

        $html = $this->forms->html('kontrak-ringkas', ['id' => $contract->id]);

        $this->assertStringContainsString('PEMILIK', $html);
        $this->assertStringContainsString('<div class="party">PT Graha Sentosa Propertindo</div>', $html);
        $this->assertStringContainsString('750.000.000,00', $html);
    }

    // ------------------------------------------------------- kontrak ringkas

    public function test_the_contract_summary_prints_the_schedule_and_both_totals(): void
    {
        $contract = $this->contract();
        $this->project($contract);

        $html = $this->forms->html('kontrak-ringkas', ['id' => $contract->id]);

        $this->assertStringContainsString('RINGKASAN KONTRAK', $html);
        $this->assertStringContainsString('CTR/2026/I/0001', $html);
        // The number the CUSTOMER knows the job by.
        $this->assertStringContainsString('SPK/GSP/2026/017', $html);
        $this->assertStringContainsString('12 bulan', $html);
        $this->assertStringContainsString('DP 20%', $html);
        $this->assertStringContainsString('Progres fisik 50% disetujui MK', $html);
        $this->assertStringContainsString('12 Februari 2026', $html);

        // Both totals, side by side: a schedule that does not cover the contract
        // is the single most useful thing this sheet can show, and it can only
        // show it by printing the two numbers next to each other.
        $this->assertStringContainsString('750.000.000,00', $html);
        $this->assertStringContainsString('1.000.000.000,00', $html);

        $this->assertStringNotContainsString('null', $html);
    }

    /**
     * A termin nobody has billed yet is a RULED BLANK in the "tanggal ditagih"
     * column — not the word "belum", not a dash and above all not a date. The
     * blank is what the finance clerk writes on when the invoice goes out.
     *
     * Asserted on the two date CELLS of one row. Every schedule on this sheet
     * carries ruled cells somewhere (the retensi row states no condition and no
     * dates at all), so `str_contains($html, '<div class="fill"></div>')` held
     * with TGL. DITAGIH filled in from the PLANNED date — a sheet claiming
     * termin 2 was invoiced on 10 Juli when nobody has invoiced it.
     */
    public function test_an_unbilled_termin_leaves_its_billing_date_ruled(): void
    {
        $contract = $this->contract();

        $html = $this->forms->html('kontrak-ringkas', ['id' => $contract->id]);

        $cells = $this->bodyRowCells($html, 'Progres 50%');

        // RENCANA TAGIH is stored and prints; TGL. DITAGIH is not and is ruled.
        $this->assertSame('10 Juli 2026', $cells[4]);
        $this->assertSame('<div class="fill"></div>', $cells[5]);
        $this->assertStringNotContainsString('Belum ditagih', $html);
    }

    /** And the row that WAS billed prints the day it was billed, in that same column. */
    public function test_a_billed_termin_prints_its_billing_date(): void
    {
        $cells = $this->bodyRowCells(
            $this->forms->html('kontrak-ringkas', ['id' => $this->contract()->id]),
            'DP 20%',
        );

        $this->assertSame('10 Februari 2026', $cells[4]);
        $this->assertSame('12 Februari 2026', $cells[5]);
    }

    // -------------------------------------------------- berita acara tambah-kurang

    /**
     * THE ASSERTION THIS DOCUMENT EXISTS FOR.
     *
     * A draft change order has not moved crm_contracts.value and must not print
     * a contract value that includes it. The arithmetic is easy and wrong: with
     * two pending change orders each sheet's "sesudah" would ignore the other,
     * and the figure people sign is the contract row itself.
     */
    public function test_a_draft_change_order_never_prints_a_contract_value_nobody_approved(): void
    {
        $contract = $this->contract();
        $order = $this->changeOrder($contract);

        $html = $this->forms->html('berita-acara-cco', ['id' => $order->id]);

        $this->assertStringContainsString('BERITA ACARA', $html);
        $this->assertStringContainsString('Penambahan pekerjaan ME lantai 9', $html);
        $this->assertStringContainsString('Permintaan pelanggan', $html);
        $this->assertStringContainsString('Tambah-Kurang', $html);
        // The change itself, and the PPN the service computed on it.
        $this->assertStringContainsString('200.000.000,00', $html);
        $this->assertStringContainsString('22.000.000,00', $html);
        // The contract as it stands — labelled as not yet including this sheet.
        $this->assertStringContainsString('1.000.000.000,00', $html);
        $this->assertStringContainsString('belum disetujui', $html);
        $this->assertStringNotContainsString('1.200.000.000,00', $html);
    }

    /** Once approved the contract row itself carries the new value, so it prints. */
    public function test_an_approved_change_order_prints_the_contract_value_it_produced(): void
    {
        $contract = $this->contract();
        $order = $this->approve($this->changeOrder($contract));

        $html = $this->forms->html('berita-acara-cco', ['id' => $order->id]);

        // What was signed (crm_contracts.original_value, backfilled on the first
        // approval) and what the contract is worth now — two questions, two
        // answers, both read off the row.
        $this->assertStringContainsString('1.000.000.000,00', $html);
        $this->assertStringContainsString('1.200.000.000,00', $html);
        $this->assertStringNotContainsString('belum disetujui', $html);
    }

    /**
     * The ERP stores a lump-sum value_change and no line items at all, so the
     * rincian table is a PAD: ruled rows for the hand that itemises the scope at
     * the site meeting. Never rows of zeros, never a repeated last value.
     */
    public function test_the_change_order_itemisation_is_a_ruled_pad(): void
    {
        $contract = $this->contract();
        $order = $this->changeOrder($contract);

        $html = $this->forms->html('berita-acara-cco', ['id' => $order->id]);

        $this->assertStringContainsString('RINCIAN PEKERJAAN', $html);
        $this->assertGreaterThanOrEqual(5, substr_count($html, '<div class="fill"></div>'));
    }

    // -------------------------------------------- berita acara addendum waktu

    /**
     * P0-B: the F/BATK layout branch. A waktu change order carries days and no
     * money, so its sheet prints the time table and NEITHER money table — the
     * itemisation pad and the NILAI KONTRAK lines are a value CCO's story, and
     * money rows on a time addendum would invite a rupiah figure to be written
     * onto an instrument that moves no rupiah.
     */
    public function test_a_waktu_cco_prints_the_time_layout_and_no_value_rows(): void
    {
        $order = $this->timeAddendum($this->contract());

        $html = $this->forms->html('berita-acara-cco', ['id' => $order->id]);

        $this->assertStringContainsString('BERITA ACARA ADDENDUM WAKTU', $html);
        $this->assertStringNotContainsString('PEKERJAAN TAMBAH / KURANG', $html);
        $this->assertStringContainsString('Addendum Waktu', $html); // JENIS PERUBAHAN, spelled
        $this->assertStringContainsString('PERUBAHAN WAKTU PELAKSANAAN', $html);
        $this->assertStringContainsString('31 Desember 2026', $html); // the signed end date
        $this->assertStringContainsString('+14 hari', $html);
        // A draft prints the CURRENT end date and says plainly it has not been
        // agreed — never end_date + days on an unapproved sheet.
        $this->assertStringContainsString('belum disetujui', $html);
        $this->assertStringNotContainsString('14 Januari 2027', $html);
        $this->assertStringNotContainsString('NILAI KONTRAK', $html);
        $this->assertStringNotContainsString('RINCIAN PEKERJAAN', $html);
    }

    /** Approved, the sheet quotes the stamped record: original and new end date. */
    public function test_an_approved_waktu_cco_quotes_its_stamped_dates(): void
    {
        $order = $this->approve($this->timeAddendum($this->contract()));

        $html = $this->forms->html('berita-acara-cco', ['id' => $order->id]);

        // What was signed (original_end_date, backfilled by the first approved
        // addendum) and what was agreed — both read off stored columns.
        $this->assertStringContainsString('31 Desember 2026', $html);
        $this->assertStringContainsString('setelah perubahan ini disetujui', $html);
        $this->assertStringContainsString('14 Januari 2027', $html);
        $this->assertStringNotContainsString('belum disetujui', $html);
    }

    /**
     * THE COMPAT PROOF for the branch that already shipped: a tambah-kurang
     * sheet prints BYTE-IDENTICALLY to the pre-P0-B renderer.
     *
     * tests/fixtures/berita-acara-cco-pra-p0b.html was captured before the
     * waktu branch existed, by rendering exactly the draft built by
     * contract() + changeOrder() and normalising the one wall-clock string on
     * the sheet — the "Dicetak …" footer. If this test fails, the waktu
     * layout has leaked into the money sheet.
     */
    public function test_a_tambah_kurang_cco_prints_byte_identically_to_the_pre_p0b_renderer(): void
    {
        $fixture = base_path('tests/fixtures/berita-acara-cco-pra-p0b.html');
        $this->assertFileExists($fixture, 'The golden fixture is part of this paket; it must not be regenerated from post-P0-B code.');

        $order = $this->changeOrder($this->contract());

        $html = preg_replace(
            '/Dicetak .* — Nusantara ERP/u',
            'Dicetak [dinormalisasi] — Nusantara ERP',
            $this->forms->html('berita-acara-cco', ['id' => $order->id]),
        );

        $this->assertSame(file_get_contents($fixture), $html);
    }

    // ------------------------------------------------------ register jaminan

    public function test_the_guarantee_register_prints_every_bond_of_the_contract(): void
    {
        $contract = $this->contract();

        Guarantee::query()->create([
            'guarantee_type' => 'performance_bond',
            'number' => 'BG/2026/0117',
            'issuer' => 'Bank Artha Nusantara',
            'contract_id' => $contract->id,
            'value' => 50_000_000,
            'start_date' => '2026-02-01',
            'end_date' => '2027-02-01',
            'status' => 'active',
            'document_location' => 'Brankas kantor pusat',
        ]);

        $expired = Guarantee::query()->create([
            'guarantee_type' => 'advance_payment_bond',
            'number' => 'BG/2026/0118',
            'issuer' => 'Bank Artha Nusantara',
            'contract_id' => $contract->id,
            'value' => 200_000_000,
            'start_date' => '2026-02-01',
            'end_date' => '2026-07-01',
            'status' => 'active',
        ]);

        $html = $this->forms->html('register-jaminan', ['id' => $expired->id]);

        $this->assertStringContainsString('REGISTER JAMINAN', $html);
        $this->assertStringContainsString('BG/2026/0117', $html);
        $this->assertStringContainsString('BG/2026/0118', $html);
        $this->assertStringContainsString('Jaminan Pelaksanaan', $html);
        $this->assertStringContainsString('Bank Artha Nusantara', $html);
        // Sisa hari counted the way the house forms count it: an obligation
        // already past its date says so instead of quietly reading "0 hari".
        $this->assertStringContainsString('0 hari (lewat 39 hari)', $html);
        // The total of the register, so a reader can check it against the
        // contract without adding the column up by hand.
        $this->assertStringContainsString('250.000.000,00', $html);
        $this->assertStringNotContainsString('null', $html);
    }

    /**
     * A bond that has been returned to us secures nothing, and "sisa 176 hari"
     * printed against it would describe an obligation that no longer exists.
     * Its dates still print; its remaining-days cell is ruled.
     */
    public function test_a_released_bond_has_no_remaining_days(): void
    {
        $contract = $this->contract();

        $released = Guarantee::query()->create([
            'guarantee_type' => 'bid_bond',
            'number' => 'BG/2026/0090',
            'issuer' => 'Bank Mandiri',
            'contract_id' => $contract->id,
            'value' => 10_000_000,
            'start_date' => '2026-01-05',
            'end_date' => '2027-01-05',
            'status' => 'released',
        ]);

        $html = $this->forms->html('register-jaminan', ['id' => $released->id]);

        // On the CELL. This bond records no LOKASI DOKUMEN FISIK, so its row is
        // ruled there whatever SISA HARI says — which is why
        // str_contains($html, '<div class="fill"></div>') stayed green with the
        // live-status guard deleted from Guarantee::daysRemaining and the row
        // reading "sisa 149 hari" against security already back in our hands.
        $cells = $this->bodyRowCells($html, 'BG/2026/0090');

        $this->assertSame('05 Januari 2027', $cells[5]);        // S/D — a stored date, still printed
        $this->assertSame('<div class="fill"></div>', $cells[6]); // SISA HARI — ruled
        $this->assertSame('Dikembalikan', $cells[7]);
        $this->assertStringNotContainsString('hari (lewat', $html);
    }

    /**
     * The screen and the sheet count the same days.
     *
     * GuaranteeResource::days_left and the register's SISA HARI column are the
     * same fact, and until this lane they were two subtractions — the resource's
     * own diffInDays and the printed one. A bond that the list screen calls
     * live while the register filed in the project folder calls it lapsed is
     * precisely the confusion the register was built to end.
     */
    public function test_the_screen_and_the_printed_register_agree_on_days_left(): void
    {
        $contract = $this->contract();

        $bond = Guarantee::query()->create([
            'guarantee_type' => 'performance_bond',
            'number' => 'BG/2026/0117',
            'issuer' => 'Bank Artha Nusantara',
            'contract_id' => $contract->id,
            'value' => 50_000_000,
            'start_date' => '2026-02-01',
            'end_date' => '2027-02-01',
            'status' => 'active',
        ]);

        $daysLeft = $this->actingAs($this->adminUser())
            ->getJson("/api/crm/guarantees/{$bond->id}")
            ->assertOk()
            ->json('data.days_left');

        $this->assertSame(176, $daysLeft);

        // The counterpart of the ruled cell above, on the same column of the
        // same table: a LIVE bond states its remaining days, so "SISA HARI is
        // ruled" is a statement about the bond's status and not about the
        // column always being blank.
        $cells = $this->bodyRowCells(
            $this->forms->html('register-jaminan', ['id' => $bond->id]),
            'BG/2026/0117',
        );

        $this->assertSame('176 hari', $cells[6]);
        $this->assertSame('Berlaku', $cells[7]);
    }

    /**
     * The cells of the single body row that names $needle, in column order.
     *
     * Every assertion in this file about a RULED cell goes through here.
     * A house sheet prints a rule wherever the ERP has nothing, so most sheets
     * carry one somewhere and `str_contains($html, '<div class="fill"></div>')`
     * is very nearly a tautology — it is what let two of the tests above stay
     * green while the cell they were written for was filled in with a guess.
     *
     * @return list<string>
     */
    private function bodyRowCells(string $html, string $needle): array
    {
        $rows = array_values(array_filter(
            preg_split('~(?=<tr\b)~', $html) ?: [],
            fn (string $row): bool => str_contains($row, $needle) && str_contains($row, '</tr>'),
        ));

        $this->assertCount(1, $rows, "expected exactly one printed row naming {$needle}");

        preg_match_all('~<td\b[^>]*>(.*?)</td>~s', $rows[0], $matches);

        return array_map(trim(...), $matches[1]);
    }

    /**
     * A bid bond is raised against a TENDER, before any contract exists — both
     * FKs are nullable for exactly that reason. The register still prints, with
     * the one row it can prove.
     */
    public function test_a_bond_without_a_contract_still_prints_its_own_row(): void
    {
        $quotation = $this->quotation();

        $bond = Guarantee::query()->create([
            'guarantee_type' => 'bid_bond',
            'number' => 'BG/2026/0201',
            'issuer' => 'Bank BNI',
            'quotation_id' => $quotation->id,
            'value' => 15_000_000,
            'start_date' => '2026-08-01',
            'end_date' => '2026-11-01',
            'status' => 'active',
        ]);

        $html = $this->forms->html('register-jaminan', ['id' => $bond->id]);

        $this->assertStringContainsString('BG/2026/0201', $html);
        $this->assertStringContainsString('QTN/2026/VIII/0007', $html);
        $this->assertStringContainsString('84 hari', $html);
    }

    // ------------------------------------------------------------- endpoint

    public function test_the_endpoint_serves_a_crm_document_as_printable_html(): void
    {
        $contract = $this->contract();

        $response = $this->actingAs($this->adminUser())
            ->get("/api/core/print/forms/kontrak-ringkas/{$contract->id}")
            ->assertOk();

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('RINGKASAN KONTRAK', $response->getContent());
    }
}
