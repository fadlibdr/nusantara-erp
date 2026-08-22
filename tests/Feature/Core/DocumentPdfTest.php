<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Company;
use Modules\Core\Services\DocumentPdfService;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Services\ArInvoiceService;
use Modules\HrPayroll\Services\PayrollService;
use Modules\Procurement\Models\PurchaseOrderItem;
use Modules\Projects\Enums\BastType;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\Project;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Feature\HrPayroll\PayrollFixtures;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Printable documents.
 *
 * The application produced no printable anything. barryvdh/laravel-dompdf sat in
 * composer.json uncalled from the first commit, resources/views held no Blade
 * file, and the only route to paper was Ctrl-P on a detail screen — which prints
 * the screen, navigation and all.
 *
 * The clearest evidence that a document was always meant to exist is
 * fin_ar_invoices.terbilang: the amount in words is computed and stored on every
 * invoice, and the only place an amount in words belongs is a printed page.
 *
 * These tests read the HTML the templates produce rather than the PDF bytes. A
 * PDF is a compressed binary stream; asserting against it means either shipping
 * a PDF parser or asserting nothing. The markup IS what dompdf lays out, so
 * reading it is reading the document — and one test does render the real PDF, to
 * pin that dompdf accepts what the templates emit.
 */
class DocumentPdfTest extends ErpTestCase
{
    use FinanceFixtures;
    use PayrollFixtures;

    /** A 1x1 transparent PNG — the smallest thing that is genuinely an image. */
    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private DocumentPdfService $documents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->documents = app(DocumentPdfService::class);

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

    private function invoice(array $attributes = []): ArInvoice
    {
        $customer = $this->makeCustomer([
            'name' => 'PT Graha Sentosa Propertindo',
            'npwp' => '01.234.567.8-011.000',
            'billing_address' => 'Graha Sentosa Tower Lt. 12',
        ]);
        $contract = $this->makeContract($customer, [
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'value' => 48_500_000_000,
        ]);

        return $this->approveInvoice($this->arInvoices()->create(array_merge([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'description' => 'Penagihan termin 1',
            'dpp' => 9_700_000_000,
            'ppn_rate' => 11.0,
            'invoice_date' => '2026-02-05',
            'due_date' => '2026-03-07',
        ], $attributes)));
    }

    // ------------------------------------------------------------- letterhead

    public function test_every_document_carries_the_company_letterhead(): void
    {
        $html = $this->documents->html('ar-invoice', $this->invoice());

        $this->assertStringContainsString('PT Nusantara Karya Integrasi', $html);
        $this->assertStringContainsString('01.234.567.8-012.000', $html, 'a document without an NPWP is not accepted by a customer');
        $this->assertStringContainsString('Jl. Raya Cakung Cilincing KM 2 No. 88', $html);
    }

    /**
     * dompdf resolves no external stylesheet and no web font, so a document that
     * depended on one would render unstyled on the machine that printed it.
     */
    public function test_the_documents_pull_in_nothing_from_outside_themselves(): void
    {
        foreach (['ar-invoice' => $this->invoice(), 'purchase-order' => $this->orderWithLines()] as $type => $record) {
            $html = $this->documents->html($type, $record);

            $this->assertStringNotContainsString('<link', $html, "{$type} must not link a stylesheet");
            $this->assertStringNotContainsString('http://', $html, "{$type} must not fetch anything remote");
            $this->assertStringNotContainsString('https://', $html, "{$type} must not fetch anything remote");
        }
    }

    // ---------------------------------------------------------------- invoice

    /** The reason terbilang is computed and stored in the first place. */
    public function test_the_invoice_prints_the_amount_in_words(): void
    {
        $invoice = $this->invoice();

        $this->assertNotEmpty($invoice->terbilang, 'the fixture must exercise the stored value, not an invented one');
        $this->assertStringContainsString($invoice->terbilang, $this->documents->html('ar-invoice', $invoice));
    }

    public function test_the_invoice_prints_who_is_being_billed_and_for_what(): void
    {
        $html = $this->documents->html('ar-invoice', $this->invoice());

        $this->assertStringContainsString('PT Graha Sentosa Propertindo', $html);
        $this->assertStringContainsString('01.234.567.8-011.000', $html, 'the customer NPWP belongs on a PPN invoice');
        $this->assertStringContainsString('Penagihan termin 1', $html);
    }

    /**
     * ppn_rate is stored as a percentage (11), not a fraction — ArInvoiceService
     * computes ppn as dpp * ppn_rate / 100. Printed as "1.100%" the document is
     * unusable, and the mistake is invisible until somebody reads the paper.
     */
    public function test_the_invoice_prints_the_ppn_rate_as_eleven_percent(): void
    {
        $html = $this->documents->html('ar-invoice', $this->invoice());

        $this->assertStringContainsString('PPN 11%', $html);
        $this->assertStringNotContainsString('1.100%', $html);
    }

    /**
     * A cancelled invoice prints byte-for-byte like a live one otherwise: same
     * faktur pajak number, same "Jumlah yang harus dibayar". The customer pays,
     * and PaymentService then refuses to settle a non-approved invoice — so the
     * money sits in the bank reconciliation with nothing to allocate it to.
     */
    public function test_a_cancelled_invoice_prints_as_cancelled(): void
    {
        $invoice = $this->invoice();
        $live = $this->documents->html('ar-invoice', $invoice);
        $this->assertStringNotContainsString('DIBATALKAN', $live);

        $cancelled = app(ArInvoiceService::class)
            ->cancel($invoice, $this->adminUser(), 'Nilai termin salah, diganti invoice baru.');

        $html = $this->documents->html('ar-invoice', $cancelled);

        $this->assertStringContainsString('DIBATALKAN', $html);
        $this->assertStringContainsString('Nilai termin salah, diganti invoice baru.', $html);
    }

    public function test_the_invoice_prints_rupiah_the_indonesian_way(): void
    {
        $html = $this->documents->html('ar-invoice', $this->invoice());

        // dpp 9.700.000.000 + PPN 11% = 10.767.000.000
        $this->assertStringContainsString('10.767.000.000,00', $html);
        $this->assertStringNotContainsString('10,767,000,000.00', $html);
    }

    public function test_the_invoice_prints_indonesian_month_names(): void
    {
        $html = $this->documents->html('ar-invoice', $this->invoice());

        $this->assertStringContainsString('05 Februari 2026', $html);
        $this->assertStringNotContainsString('February', $html);
    }

    // --------------------------------------------------------- purchase order

    private function orderWithLines()
    {
        $order = $this->makePurchaseOrder($this->makeVendor(['name' => 'PT Semen Distribusi Utama']), [
            'subtotal' => 209_500_000,
            'dpp' => 209_500_000,
            'ppn_amount' => 23_045_000,
            'total' => 232_545_000,
        ]);

        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $order->id,
            'line_no' => 1,
            'description' => 'Semen Portland 50kg',
            'qty' => 2000,
            'unit' => 'zak',
            'unit_price' => 62_000,
            'amount' => 124_000_000,
        ]);
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $order->id,
            'line_no' => 2,
            'description' => 'Pasir Beton',
            'qty' => 300,
            'unit' => 'm3',
            'unit_price' => 285_000,
            'amount' => 85_500_000,
        ]);

        return $order->refresh();
    }

    public function test_the_purchase_order_prints_every_line_the_vendor_must_deliver(): void
    {
        $html = $this->documents->html('purchase-order', $this->orderWithLines());

        $this->assertStringContainsString('Semen Portland 50kg', $html);
        $this->assertStringContainsString('124.000.000,00', $html);
        $this->assertStringContainsString('Pasir Beton', $html);
        $this->assertStringContainsString('85.500.000,00', $html);
        $this->assertStringContainsString('232.545.000,00', $html);
    }

    /**
     * A purchase order is a document three people sign — the approver, the buyer
     * and the vendor receiving it. That is the whole reason it is printed.
     */
    public function test_the_purchase_order_leaves_room_for_three_signatures(): void
    {
        $html = $this->documents->html('purchase-order', $this->orderWithLines());

        $this->assertStringContainsString('Menyetujui', $html);
        $this->assertStringContainsString('Dibuat oleh', $html);
        $this->assertStringContainsString('Diterima vendor', $html);
    }

    /**
     * An absent date must print as nothing, never as 01 Januari 1970. A PO with
     * no promised delivery date is ordinary — the row simply does not appear.
     */
    public function test_a_missing_date_prints_as_nothing(): void
    {
        $order = $this->orderWithLines();
        $order->forceFill(['expected_date' => null])->save();

        $html = $this->documents->html('purchase-order', $order->refresh());

        $this->assertStringNotContainsString('1970', $html);
        $this->assertStringNotContainsString('Diharapkan tiba', $html);
    }

    /** Nothing on the order is stored as words, so the document spells it. */
    public function test_the_purchase_order_spells_out_its_total(): void
    {
        $html = $this->documents->html('purchase-order', $this->orderWithLines());

        $this->assertStringContainsString(
            'Dua ratus tiga puluh dua juta lima ratus empat puluh lima ribu rupiah',
            $html,
        );
    }

    // ------------------------------------------------------------------ BAST

    private function bast(array $attributes = []): Bast
    {
        $customer = $this->makeCustomer(['name' => 'PT Graha Sentosa Propertindo']);
        $project = Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'customer_id' => $customer->id,
            'type' => 'construction',
            'status' => 'finishing',
            'location' => 'Jl. TB Simatupang Kav. 18',
            'city' => 'Jakarta Selatan',
            'contract_value' => 48_500_000_000,
            'retention_pct' => 5.0,
            'warranty_months' => 12,
        ]);

        return Bast::query()->create(array_merge([
            'code' => 'BAST/2026/VII/0001',
            'project_id' => $project->id,
            'bast_type' => BastType::Bast1,
            'handover_date' => '2026-07-15',
            'customer_representative' => 'Ir. Hendra Kusuma',
            'retention_release_due' => '2027-07-15',
            'status' => DocumentStatus::Approved,
        ], $attributes));
    }

    /**
     * The document with the plainest reason to exist: prj_bast has recorded the
     * name of the person who signs the handover since the first migration, and
     * there was no page for them to sign.
     */
    public function test_the_bast_prints_both_parties_and_what_was_handed_over(): void
    {
        $html = $this->documents->html('bast', $this->bast());

        $this->assertStringContainsString('Pembangunan Gedung Kantor Graha Sentosa', $html);
        $this->assertStringContainsString('PT Graha Sentosa Propertindo', $html);
        $this->assertStringContainsString('Ir. Hendra Kusuma', $html);
        $this->assertStringContainsString('BAST I', $html);
        $this->assertStringContainsString('15 Juli 2026', $html);
    }

    /**
     * Signing a BAST starts the clock on the customer's retention. The date the
     * money becomes claimable is the financial consequence of the signature and
     * belongs on the page being signed.
     */
    public function test_the_bast_prints_when_the_retention_falls_due(): void
    {
        $html = $this->documents->html('bast', $this->bast());

        $this->assertStringContainsString('Retensi jatuh tempo', $html);
        $this->assertStringContainsString('15 Juli 2027', $html);
        // 5% of a 48,5 miliar contract.
        $this->assertStringContainsString('2.425.000.000,00', $html);
    }

    // ---------------------------------------------------------------- payslip

    private function calculatedPayslip()
    {
        $employee = $this->makeEmployee([
            'name' => 'Budi Santoso',
            'position' => 'Pelaksana Lapangan',
            'department' => 'proyek',
            'base_salary' => 12_000_000,
        ]);
        $run = $this->makeRun();
        app(PayrollService::class)->calculate($run);

        return $this->payslipFor($run->refresh(), $employee);
    }

    public function test_the_payslip_prints_what_the_employee_earned_and_what_was_taken(): void
    {
        $payslip = $this->calculatedPayslip();
        $html = $this->documents->html('payslip', $payslip);

        $this->assertStringContainsString('Budi Santoso', $html);
        $this->assertStringContainsString('Pelaksana Lapangan', $html);
        $this->assertStringContainsString('Penghasilan bruto', $html);
        $this->assertStringContainsString('Jumlah potongan', $html);
        $this->assertStringContainsString(number_format((float) $payslip->net_pay, 2, ',', '.'), $html);
    }

    /**
     * The employer's BPJS contribution is a cost to the company, not a deduction
     * from the employee. Printed among the deductions it reads as money taken,
     * and that is the single most common payslip dispute there is.
     */
    public function test_the_payslip_says_the_employer_bpjs_does_not_reduce_take_home_pay(): void
    {
        $html = $this->documents->html('payslip', $this->calculatedPayslip());

        $this->assertStringContainsString('tidak mengurangi penghasilan bersih', $html);
    }

    /**
     * The slip spells the department the way every screen does.
     *
     * hr_employees.department is a plain string column with no cast, so the
     * Jabatan line printed the stored slug: "Teknisi Senior — servis" on the
     * one document an employee actually takes home, while the HR screen the
     * row was created on says "Servis" and the pengajuan cuti form — the other
     * sheet that names a department — already prints the label.
     * Department::labelFor exists for exactly this and says so in its own
     * docblock.
     */
    public function test_the_payslip_spells_the_department_the_way_the_screens_do(): void
    {
        $employee = $this->makeEmployee([
            'name' => 'Rizal Mahendra',
            'position' => 'Teknisi Senior',
            'department' => 'servis',
            'base_salary' => 8_200_000,
        ]);
        $run = $this->makeRun();
        app(PayrollService::class)->calculate($run);

        $html = $this->documents->html('payslip', $this->payslipFor($run->refresh(), $employee));

        $this->assertStringContainsString('<td>Teknisi Senior — Servis</td>', $html);
        $this->assertStringNotContainsString('servis</td>', $html);
    }

    /**
     * A department outside the six prints AS ITSELF.
     *
     * Not blank, which would hide something the database holds, and never
     * mapped onto the nearest known department — a slip carrying another
     * department's name is worse than one carrying an unfamiliar spelling.
     * Rows predating the Rule::in are exactly this case.
     *
     * AND THE DISCRIMINATING HALF, WHICH THIS TEST USED TO ADMIT IT LACKED.
     * Printed straight off the column an unknown slug comes out verbatim TOO,
     * so the assertion below passed with Department::labelFor removed
     * altogether — it cannot tell the enum from the raw column, which is the
     * whole question. A slug the enum DOES know is therefore rendered from the
     * same run, where the stored value and the printed label differ.
     */
    public function test_a_payslip_department_outside_the_six_prints_verbatim(): void
    {
        $unknown = $this->makeEmployee([
            'position' => 'Kepala Divisi',
            'department' => 'Divisi Lama',
            'base_salary' => 9_000_000,
        ]);

        $known = $this->makeEmployee([
            'position' => 'Teknisi Senior',
            'department' => 'servis',
            'base_salary' => 8_200_000,
        ]);

        $run = $this->makeRun();
        app(PayrollService::class)->calculate($run);
        $run->refresh();

        $this->assertStringContainsString(
            '<td>Kepala Divisi — Divisi Lama</td>',
            $this->documents->html('payslip', $this->payslipFor($run, $unknown)),
        );

        $mapped = $this->documents->html('payslip', $this->payslipFor($run, $known));

        $this->assertStringContainsString('<td>Teknisi Senior — Servis</td>', $mapped);
        $this->assertStringNotContainsString('servis</td>', $mapped);
    }

    public function test_the_payslip_names_its_period(): void
    {
        $html = $this->documents->html('payslip', $this->calculatedPayslip());

        $this->assertStringContainsString('Slip Gaji Juni 2026', $html);
    }

    // ------------------------------------------------------------- the PDF

    /**
     * The one test that renders the real thing: whatever the templates emit,
     * dompdf has to accept it and produce a PDF a viewer will open.
     */
    public function test_a_real_pdf_comes_out_the_other_end(): void
    {
        $document = $this->documents->pdf('ar-invoice', $this->invoice());

        $this->assertSame('%PDF', substr($document['body'], 0, 4));
        $this->assertGreaterThan(2000, strlen($document['body']));
        // Font subsetting is off in the package's own config; without turning it
        // on, dompdf embeds the whole of DejaVu Sans and this weighs 1.2 MB.
        $this->assertLessThan(300_000, strlen($document['body']));
    }

    /** Document codes carry slashes, which no filename can. */
    public function test_the_filename_is_one_a_browser_can_save(): void
    {
        $document = $this->documents->pdf('ar-invoice', $this->invoice());

        $this->assertMatchesRegularExpression('/^invoice-[A-Za-z0-9-]+\.pdf$/', $document['filename']);
        $this->assertStringNotContainsString('/', $document['filename']);
    }

    // ------------------------------------------------------------------- logo

    /**
     * core_company.logo_path sat in the schema referenced by nothing at all —
     * the deadest column in the database, because there was no document to put
     * a logo on.
     */
    public function test_the_letterhead_carries_the_company_logo(): void
    {
        Storage::fake('public')->put('logo.png', base64_decode(self::ONE_PIXEL_PNG));
        Company::query()->first()->forceFill(['logo_path' => 'logo.png'])->save();

        $this->assertStringContainsString(
            'data:image/png;base64,'.self::ONE_PIXEL_PNG,
            $this->documents->html('ar-invoice', $this->invoice()),
            'the logo must be inlined — dompdf is run with file and remote access off',
        );
    }

    /**
     * A letterhead without a logo is a letterhead. A broken image is not, and a
     * logo_path pointing at something unreadable must not decide whether an
     * invoice can be sent.
     */
    public function test_an_unusable_logo_leaves_the_letterhead_alone(): void
    {
        Storage::fake('public')->put('sertifikat.pdf', 'bukan gambar');

        foreach (['tidak-ada.png', 'sertifikat.pdf', '../../.env'] as $path) {
            Company::query()->first()->forceFill(['logo_path' => $path])->save();

            $html = $this->documents->html('ar-invoice', $this->invoice());

            $this->assertStringNotContainsString('<img', $html, "{$path} must not reach the page");
            $this->assertStringContainsString('PT Nusantara Karya Integrasi', $html);
        }
    }

    public function test_an_unknown_document_type_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->documents->html('surat-jalan', $this->invoice());
    }

    // ------------------------------------------------------------- endpoints

    public function test_the_endpoint_serves_the_invoice_as_a_pdf(): void
    {
        $invoice = $this->invoice();

        $response = $this->actingAs($this->adminUser())
            ->get("/api/core/print/ar-invoices/{$invoice->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));
    }

    public function test_the_endpoint_serves_a_purchase_order_and_a_payslip(): void
    {
        $order = $this->orderWithLines();
        $payslip = $this->calculatedPayslip();
        $admin = $this->adminUser();

        $this->actingAs($admin)->get("/api/core/print/purchase-orders/{$order->id}")->assertOk();
        $this->actingAs($admin)->get("/api/core/print/payslips/{$payslip->id}")->assertOk();
        $this->actingAs($admin)->get('/api/core/print/bast/'.$this->bast()->id)->assertOk();
    }

    /** Printing a document is reading it; the module's view permission applies. */
    public function test_printing_needs_the_permission_to_see_the_record(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->userWithout('fin.view'))
            ->get("/api/core/print/ar-invoices/{$invoice->id}")
            ->assertForbidden();
    }

    private function userWithout(string $permission)
    {
        $user = $this->adminUser();
        $user->roles->first()->revokePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->refresh();
    }
}
