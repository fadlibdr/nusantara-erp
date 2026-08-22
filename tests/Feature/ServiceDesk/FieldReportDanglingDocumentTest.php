<?php

namespace Tests\Feature\ServiceDesk;

use LogicException;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Services\PeriodCloseService;
use Modules\Finance\Support\DanglingDocuments;
use Modules\Inventory\Enums\ItemType;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Modules\ServiceDesk\Enums\FieldReportStatus;
use Modules\ServiceDesk\Models\FieldReport;
use Modules\ServiceDesk\Models\Ticket;
use Modules\ServiceDesk\Services\FieldReportService;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * A SUBMITTED FIELD REPORT PINS ITS DATE, so the close has to know about it.
 *
 * A field report looks like a ServiceDesk document and is one — until the
 * customer signs, at which point FieldReportService::acknowledge() raises a
 * POSTED inventory issue dated on report_date ("Dated on the visit, not on the
 * click … and that is the date the GL period check must judge"). The
 * DanglingDocuments registry — whose whole job is listing documents whose posting
 * date is pinned inside a period — did not have it.
 *
 * The audit's case: PM/2026/VI/0007 submitted on 2026-06-20 with 3 x ITM-0004
 * fitted on a customer roof. Finance closes June on 5 July because the item
 * reports zero. The customer signs on 8 July and acknowledge throws "Periode
 * fiskal 2026-06 sudah ditutup" from then on — and the report cannot be re-dated
 * or deleted either, because FieldReportStatus::isEditable() is Draft-only. All
 * three escapes the registry's own docblock promises ("post it, delete it, or
 * re-date it") are shut. Finance can still reopen June, but only until a posted
 * PSAK 115 run measures the month.
 */
class FieldReportDanglingDocumentTest extends ErpTestCase
{
    use InventoryFixtures;

    private const YEAR = 2026;

    private const MONTH = 6;

    /** Cross-module id: HrPayroll owns hr_employees, there is no FK to satisfy. */
    private const TECHNICIAN_ID = 7;

    private Warehouse $gudang;

    private Item $kamera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->gudang = $this->makeWarehouse('WH-PUSAT');
        $this->kamera = $this->makeItem('CCTV Dome 4MP', [
            'unit' => 'unit',
            'item_type' => ItemType::Sparepart,
        ]);

        $this->receiveStock($this->gudang, $this->kamera, 30, 1850000, '2026-06-01');
    }

    public function test_a_submitted_report_carrying_parts_is_a_dangling_document(): void
    {
        $report = $this->submittedReport([[$this->kamera, 3]]);

        $scan = DanglingDocuments::scan(self::YEAR, self::MONTH);

        $this->assertSame(1, DanglingDocuments::total($scan));
        $this->assertSame('svc_field_reports', $scan[0]['source']);
        $this->assertSame('Laporan lapangan', $scan[0]['label']);
        $this->assertSame([$report->code.' (diajukan)'], $scan[0]['codes']);
        $this->assertSame('r/servicedesk/field-reports', $scan[0]['link']);
    }

    public function test_it_blocks_the_close_of_the_month_it_is_dated_in(): void
    {
        $this->submittedReport([[$this->kamera, 3]]);

        $item = $this->checklistItem('dangling_documents');

        $this->assertSame(PeriodCloseService::BLOCK, $item['severity']);
        $this->assertSame(PeriodCloseService::FAIL, $item['status']);
        $this->assertStringContainsString('Juni 2026', $item['detail']);
    }

    public function test_acknowledging_it_first_clears_the_block_and_the_gl_lands_on_the_visit(): void
    {
        // The escape the registry promises: post it. And the reason it matters —
        // 3 x 1.850.000 = Rp 5.550.000 reaches the ledger on 2026-06-10, inside
        // the month being closed, exactly where the accountant expects it.
        $report = $this->submittedReport([[$this->kamera, 3]]);

        app(FieldReportService::class)->acknowledge($report, 'Darto Prasetyo');

        $this->assertSame(FieldReportStatus::Acknowledged, $report->fresh()->status);
        $this->assertSame(27.0, $this->balanceQty($this->gudang, $this->kamera));
        $this->assertSame(0, DanglingDocuments::total(DanglingDocuments::scan(self::YEAR, self::MONTH)));
        $this->assertSame(PeriodCloseService::OK, $this->checklistItem('dangling_documents')['status']);
    }

    public function test_a_signature_only_report_pins_nothing_and_does_not_block(): void
    {
        // A visit that consumed no parts issues nothing and raises no journal, so
        // its report_date binds nothing. Blocking on it would be the theatre the
        // registry's docblock exists to avoid.
        $this->submittedReport();

        $this->assertSame(0, DanglingDocuments::total(DanglingDocuments::scan(self::YEAR, self::MONTH)));
        $this->assertSame(PeriodCloseService::OK, $this->checklistItem('dangling_documents')['status']);
    }

    public function test_an_acknowledged_or_draft_report_is_not_dangling(): void
    {
        // Draft: nothing has been submitted for signature, so nothing is pending
        // against the period. Acknowledged: its bon is already in the ledger.
        $this->submittedReport([[$this->kamera, 1]], ['status' => FieldReportStatus::Draft]);

        $done = $this->submittedReport([[$this->kamera, 1]]);
        app(FieldReportService::class)->acknowledge($done, 'Darto Prasetyo');

        $this->assertSame(0, DanglingDocuments::total(DanglingDocuments::scan(self::YEAR, self::MONTH)));
    }

    public function test_a_report_dated_in_another_month_does_not_block_this_one(): void
    {
        $this->submittedReport([[$this->kamera, 3]], ['report_date' => '2026-07-02']);

        $this->assertSame(0, DanglingDocuments::total(DanglingDocuments::scan(self::YEAR, self::MONTH)));
        $this->assertSame(1, DanglingDocuments::total(DanglingDocuments::scan(2026, 7)));
    }

    public function test_periodic_inventory_leaves_it_out_like_every_other_stock_document(): void
    {
        // Under periodic no stock movement reaches the ledger at all, so nothing
        // about the report's date is pinned to a fiscal period — the same rule
        // the three inventory sources already carry.
        $this->submittedReport([[$this->kamera, 3]]);

        $this->setSetting('accounting.perpetual_inventory', false);

        $this->assertSame(0, DanglingDocuments::total(DanglingDocuments::scan(self::YEAR, self::MONTH)));
    }

    public function test_the_wedge_the_registry_entry_prevents(): void
    {
        // What used to happen with the item missing: the close proceeds, and the
        // signature is then refused for ever on a report that can be neither
        // re-dated nor deleted.
        $report = $this->submittedReport([[$this->kamera, 3]]);

        FiscalPeriod::query()->where('year', self::YEAR)->where('month', self::MONTH)
            ->update(['status' => 'closed']);

        try {
            app(FieldReportService::class)->acknowledge($report, 'Darto Prasetyo');
            $this->fail('Expected the closed period to refuse the acknowledgement.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Periode fiskal 2026-06 sudah ditutup', $e->getMessage());
        }

        $this->assertSame(FieldReportStatus::Submitted, $report->fresh()->status);
        $this->assertFalse($report->fresh()->status->isEditable());
        $this->assertSame(30.0, $this->balanceQty($this->gudang, $this->kamera));
    }

    // ----------------------------------------------------------------- fixtures

    /**
     * @return array<string, mixed>
     */
    private function checklistItem(string $key): array
    {
        foreach (app(PeriodCloseService::class)->checklist(self::YEAR, self::MONTH) as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        $this->fail("Checklist item {$key} not found.");
    }

    /**
     * @param  array<int, array{0: Item, 1: float}>  $parts
     * @param  array<string, mixed>  $attributes
     */
    private function submittedReport(array $parts = [], array $attributes = []): FieldReport
    {
        $ticket = Ticket::create([
            'customer_id' => 1, // crm_customers.id (cross-module, no FK)
            'title' => 'Kamera lobi mati total',
            'priority' => 'high',
            'reported_at' => '2026-06-09 08:00:00',
        ]);

        $report = FieldReport::create(array_merge([
            'ticket_id' => $ticket->id,
            'report_date' => '2026-06-10',
            'technician_employee_id' => self::TECHNICIAN_ID,
            'warehouse_id' => $this->gudang->id,
            'findings' => '3 unit kamera dome lobi mati total.',
            'actions_taken' => 'Penggantian 3 unit CCTV Dome 4MP.',
            'status' => FieldReportStatus::Submitted,
        ], $attributes));

        foreach ($parts as [$item, $qty]) {
            $report->parts()->create(['item_id' => $item->id, 'qty' => $qty]);
        }

        return $report->refresh();
    }
}
