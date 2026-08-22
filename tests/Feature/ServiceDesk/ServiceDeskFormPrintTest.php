<?php

namespace Tests\Feature\ServiceDesk;

use Illuminate\Support\Carbon;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Crm\Models\Customer;
use Modules\HrPayroll\Models\Employee;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\ServiceDesk\Models\ContractSite;
use Modules\ServiceDesk\Models\FieldReport;
use Modules\ServiceDesk\Models\FieldReportPart;
use Modules\ServiceDesk\Models\PreventiveSchedule;
use Modules\ServiceDesk\Models\ServiceContract;
use Modules\ServiceDesk\Models\Ticket;
use Tests\ErpTestCase;

/**
 * Formulir rumah untuk modul Layanan — berita acara servis, ringkasan kontrak
 * layanan.
 *
 * THE ONE CELL THIS FILE IS REALLY ABOUT is the customer's name on the
 * signature rule of a berita acara. svc_field_reports.customer_sign_name looks
 * exactly like a record of who signed, and FieldReportService says in its own
 * words that it is not: "customer_sign_name is left alone too, though create()
 * and update() can both write it, so it is not proof of a signature on its
 * own." What IS proof is customer_signed_at — only acknowledge() ever writes
 * it, and acknowledge() is the transaction that posts the parts off the shelf.
 *
 * So the name prints under the rule when the report is ACKNOWLEDGED and
 * carries its timestamp, and is a ruled blank in every other state. A
 * technician who typed a name into a draft has typed a name into a draft; a
 * sheet that printed it as a signature would put words in a customer's mouth
 * on the document that closes the visit and consumes the spare parts.
 */
class ServiceDeskFormPrintTest extends ErpTestCase
{
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
            'name' => 'PT Angkasa Pura I (Persero)',
            'billing_address' => 'Bandara Internasional Juanda, Sidoarjo',
            'is_pkp' => true,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
    }

    private function serviceContract(array $attributes = []): ServiceContract
    {
        $contract = ServiceContract::query()->create(array_merge([
            'code' => 'SVC/2026/I/0002',
            'customer_id' => $this->customer()->id,
            'name' => 'Pemeliharaan Sistem CCTV & Access Control Terminal 2',
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'contract_value' => 480_000_000,
            'billing_cycle' => 'quarterly',
            'sla_response_hours' => 4,
            'sla_resolution_hours' => 24,
            'coverage' => 'Meliputi 128 kamera IP, 4 NVR dan 26 pintu access control. '
                .'Tidak termasuk penggantian perangkat akibat sambaran petir.',
            'status' => 'active',
        ], $attributes));

        $site = ContractSite::query()->create([
            'service_contract_id' => $contract->id,
            'site_name' => 'Terminal 2 Keberangkatan',
            'address' => 'Bandara Juanda, Jl. Ir. H. Juanda',
            'city' => 'Sidoarjo',
            'pic_name' => 'Hendra Wijaya',
            'pic_phone' => '0812-3456-7890',
        ]);

        PreventiveSchedule::query()->create([
            'service_contract_id' => $contract->id,
            'site_id' => $site->id,
            'name' => 'Pembersihan lensa dan pengecekan fokus kamera',
            'frequency' => 'monthly',
            'next_due_date' => '2026-09-05',
            'is_active' => true,
        ]);

        return $contract->fresh();
    }

    private function ticket(): Ticket
    {
        $contract = $this->serviceContract();

        return Ticket::query()->create([
            'code' => 'TKT/2026/VIII/0031',
            'service_contract_id' => $contract->id,
            'customer_id' => $contract->customer_id,
            'site_id' => $contract->sites()->value('id'),
            'title' => 'Empat kamera area check-in mati total',
            'description' => 'Dilaporkan sejak pukul 04.30 oleh petugas AVSEC',
            'category' => 'incident',
            'priority' => 'high',
            'status' => 'resolved',
            'channel' => 'phone',
            'reported_at' => '2026-08-04 04:45:00',
        ]);
    }

    private function technician(): Employee
    {
        return Employee::query()->create([
            'code' => 'EMP-0022',
            'name' => 'Rizal Mahendra',
            'nik_ktp' => '3578011203920005',
            'gender' => 'male',
            'birth_date' => '1992-03-12',
            'ptkp_status' => 'TK/0',
            'join_date' => '2023-02-01',
            'employment_type' => 'tetap',
            'position' => 'Teknisi Senior',
            'department' => 'Layanan Purna Jual',
            'base_salary' => 8_200_000,
            'status' => 'active',
        ]);
    }

    private function fieldReport(array $attributes = []): FieldReport
    {
        $report = FieldReport::query()->create(array_merge([
            'code' => 'PM/2026/VIII/0009',
            'ticket_id' => $this->ticket()->id,
            'report_date' => '2026-08-04',
            'technician_employee_id' => $this->technician()->id,
            'findings' => 'Power supply 12V DC pada rak lantai 2 rusak, tegangan keluaran 4,1V.',
            'actions_taken' => 'Mengganti power supply dan menguji ulang keempat kamera.',
            'recommendations' => 'Tambahkan UPS pada rak lantai 2 sebelum musim hujan.',
            'status' => 'draft',
            'customer_sign_name' => 'Dwi Kurniawan',
        ], $attributes));

        $item = Item::query()->create([
            'code' => 'ITM-0041',
            'name' => 'Power supply 12V DC 20A',
            'unit' => 'unit',
            'item_type' => 'sparepart',
            'category_id' => ItemCategory::query()->create(['code' => 'CAT-SP', 'name' => 'Suku Cadang'])->id,
            'is_active' => true,
        ]);

        FieldReportPart::query()->create([
            'field_report_id' => $report->id,
            'item_id' => $item->id,
            'qty' => 1,
            'notes' => 'Terpasang di rak lantai 2',
        ]);

        return $report->fresh();
    }

    /**
     * One identity ROW — the label and the value together, in the markup that
     * puts them in the same row of the block.
     *
     * assertStringContainsString('SISA MASA BERLAKU') holds on every copy of
     * this sheet, because the label is printed whether or not the line has an
     * answer; 'belum berjalan' holds wherever it appears. Neither says the two
     * met in one cell, which is the only thing worth asserting about a value.
     */
    private function identityCell(string $label, string $value): string
    {
        return '~>'.preg_quote($label, '~').'</td>\s*<td class="s">:</td>\s*<td class="v">\s*'
            .preg_quote($value, '~').'\s*</td>~';
    }

    // ------------------------------------------------------ berita acara

    public function test_the_service_report_prints_the_visit_and_its_parts(): void
    {
        $html = $this->forms->html('berita-acara-servis', ['id' => $this->fieldReport()->id]);

        $this->assertStringContainsString('BERITA ACARA PEKERJAAN SERVIS', $html);
        $this->assertStringContainsString('Form F/BS', $html);
        $this->assertStringContainsString('PM/2026/VIII/0009', $html);
        $this->assertStringContainsString('TKT/2026/VIII/0031', $html);
        $this->assertStringContainsString('PT Angkasa Pura I (Persero)', $html);
        $this->assertStringContainsString('Terminal 2 Keberangkatan', $html);
        $this->assertStringContainsString('Rizal Mahendra', $html);
        $this->assertStringContainsString('Power supply 12V DC pada rak lantai 2 rusak', $html);
        $this->assertStringContainsString('Mengganti power supply dan menguji ulang keempat kamera.', $html);
        $this->assertStringContainsString('Tambahkan UPS pada rak lantai 2', $html);
        $this->assertStringContainsString('ITM-0041', $html);
        $this->assertStringContainsString('Power supply 12V DC 20A', $html);
    }

    /**
     * A draft's typed name is not a signature. See the class docblock — the
     * module says so itself, and acknowledge() is the only writer of the
     * timestamp that makes it one.
     */
    public function test_an_unacknowledged_report_rules_the_customer_signature(): void
    {
        $html = $this->forms->html('berita-acara-servis', ['id' => $this->fieldReport()->id]);

        $this->assertStringContainsString('Diterima &amp; disetujui,', $html);
        $this->assertStringNotContainsString('Dwi Kurniawan', $html);
        // The rule is still drawn — it is the paper's own blank, waiting for a pen.
        $this->assertStringContainsString('sig-name', $html);
    }

    public function test_an_acknowledged_report_prints_the_signatory_it_recorded(): void
    {
        $html = $this->forms->html('berita-acara-servis', [
            'id' => $this->fieldReport([
                'status' => 'acknowledged',
                'customer_signed_at' => '2026-08-05 16:20:00',
            ])->id,
        ]);

        $this->assertStringContainsString('Dwi Kurniawan', $html);
        $this->assertStringContainsString('DITANDATANGANI PELANGGAN', $html);
        $this->assertStringContainsString('05 Agustus 2026', $html);
    }

    /** A visit that fitted nothing says so; it does not print a row of zeros. */
    public function test_a_visit_without_parts_says_so(): void
    {
        $report = $this->fieldReport();
        $report->parts()->delete();

        $html = $this->forms->html('berita-acara-servis', ['id' => $report->id]);

        $this->assertStringContainsString('Kunjungan ini tidak menggunakan suku cadang.', $html);
        $this->assertStringNotContainsString('0,00', $html);
    }

    // ----------------------------------------------------- kontrak layanan

    public function test_the_service_contract_sheet_prints_the_sla_sites_and_pm_schedule(): void
    {
        $html = $this->forms->html('kontrak-layanan', ['id' => $this->serviceContract()->id]);

        $this->assertStringContainsString('RINGKASAN KONTRAK LAYANAN', $html);
        $this->assertStringContainsString('Form F/KL', $html);
        $this->assertStringContainsString('SVC/2026/I/0002', $html);
        $this->assertStringContainsString('01 Januari 2026', $html);
        $this->assertStringContainsString('31 Desember 2026', $html);
        $this->assertStringContainsString('480.000.000,00', $html);
        // Per triwulan: 480.000.000 / 4.
        $this->assertStringContainsString('120.000.000,00', $html);
        $this->assertStringContainsString('4 jam', $html);
        $this->assertStringContainsString('24 jam', $html);
        $this->assertStringContainsString('Terminal 2 Keberangkatan', $html);
        $this->assertStringContainsString('Hendra Wijaya', $html);
        $this->assertStringContainsString('Pembersihan lensa dan pengecekan fokus kamera', $html);
        $this->assertStringContainsString('05 September 2026', $html);
        $this->assertStringContainsString('Tidak termasuk penggantian perangkat akibat sambaran petir.', $html);
    }

    /**
     * The line a service manager reads first. It is counted the way the house
     * forms count sisa hari — an expired contract says how far past it is
     * rather than "0 hari", because a contract in its grace period is exactly
     * the one somebody is about to raise a ticket against.
     */
    public function test_an_expired_contract_says_how_far_past_its_period_it_is(): void
    {
        $html = $this->forms->html('kontrak-layanan', [
            'id' => $this->serviceContract([
                'code' => 'SVC/2025/I/0001',
                'period_start' => '2025-01-01',
                'period_end' => '2025-12-31',
                'status' => 'expired',
            ])->id,
        ]);

        $this->assertStringContainsString('SISA MASA BERLAKU', $html);
        $this->assertStringContainsString('lewat 221 hari', $html);
    }

    /**
     * The remainder is counted as at the SHEET's date, not as at the printer's
     * clock.
     *
     * contractRemaining() has always taken an $asOf and nobody passed it, so a
     * sheet headed "Jakarta Timur, 01 Januari 2026" reported the days left as
     * at the day somebody pressed print. On a contract that had not begun on
     * the date the sheet claimed, that is a cover period counted backwards
     * from the future.
     *
     * The contract here is the RUNNING one — 1 Januari to 31 Desember 2026,
     * with the clock inside it at 9 Agustus — and the sheet is dated before it
     * began. That is the only shape that can tell the two apart: the clock's
     * answer is "144 hari" and the sheet's is "belum berjalan". An earlier
     * version of this test used a contract that had not started on EITHER
     * date, so both answers were "belum berjalan" and it passed with the date
     * thrown away.
     */
    public function test_the_remaining_period_is_counted_as_at_the_sheets_own_date(): void
    {
        $contract = $this->serviceContract();

        $html = $this->forms->html('kontrak-layanan', [
            'id' => $contract->id,
            'date' => '2025-06-30',
        ]);

        $this->assertMatchesRegularExpression(
            $this->identityCell('SISA MASA BERLAKU', 'belum berjalan (mulai 01 Januari 2026)'),
            $html,
        );
        // The printer's clock, which is what this cell used to answer with.
        $this->assertStringNotContainsString('144 hari', $html);
    }

    /**
     * What "sisa" MEANS at the two ends, pinned on the paper.
     *
     * The last covered day reads "0 hari" — the cover has not lapsed, and
     * there are no whole days left after today — and the day after reads
     * "0 hari (lewat 1 hari)". That is a REMAINDER counted with neither end
     * added on, which is what FormPrintService::schedule() does for sisa hari
     * on every house form, and matching it is the point: a kontrak layanan and
     * a laporan harian printed the same morning may not mean two different
     * things by the same word. contractRemaining()'s docblock claimed the
     * count was inclusive of both ends; these three dates are what it actually
     * does.
     */
    public function test_the_remaining_period_counts_neither_end_twice(): void
    {
        $contract = $this->serviceContract();

        foreach ([
            '2026-12-30' => '1 hari',
            '2026-12-31' => '0 hari',
            '2027-01-01' => '0 hari (lewat 1 hari)',
        ] as $date => $expected) {
            $html = $this->forms->html('kontrak-layanan', ['id' => $contract->id, 'date' => $date]);

            $this->assertMatchesRegularExpression(
                $this->identityCell('SISA MASA BERLAKU', $expected),
                $html,
                "Sheet dated {$date} must read '{$expected}'.",
            );
        }
    }

    /** And once it is running, the count follows the sheet's date too. */
    public function test_the_remaining_period_moves_with_the_sheets_date(): void
    {
        $contract = $this->serviceContract();

        $asked = $this->forms->html('kontrak-layanan', [
            'id' => $contract->id,
            'date' => '2026-12-01',
        ]);

        // 1 Desember 2026 to 31 Desember 2026 is 30 days.
        $this->assertStringContainsString('30 hari', $asked);
    }
}
