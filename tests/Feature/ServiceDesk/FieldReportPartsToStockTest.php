<?php

namespace Tests\Feature\ServiceDesk;

use DomainException;
use LogicException;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\ProjectCost;
use Modules\Inventory\Enums\ItemType;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Warehouse;
use Modules\ServiceDesk\Enums\FieldReportStatus;
use Modules\ServiceDesk\Models\FieldReport;
use Modules\ServiceDesk\Models\Ticket;
use Modules\ServiceDesk\Services\FieldReportService;
use Tests\ErpTestCase;
use Tests\Feature\Inventory\AssertsJournals;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * The parts-to-stock bridge: acknowledging a field report turns its spare
 * parts into a posted inventory issue — stock out at moving average, GL entry,
 * one transaction with the signature. Modelled on the live case this bridge
 * exists to prevent repeating: PM/2026/VI/0001 replaced 1 x CCTV Dome 4MP
 * (Rp 1.850.000) and the camera never left inv_stock_balances.
 */
class FieldReportPartsToStockTest extends ErpTestCase
{
    use AssertsJournals;
    use InventoryFixtures;

    /** Cross-module id: HrPayroll owns hr_employees, there is no FK to satisfy. */
    private const TECHNICIAN_ID = 7;

    private Warehouse $gudang;

    private Item $kamera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->gudang = $this->makeWarehouse('WH-SVC');
        $this->kamera = $this->makeItem('CCTV Dome 4MP', [
            'unit' => 'unit',
            'item_type' => ItemType::Sparepart,
        ]);
    }

    private function reports(): FieldReportService
    {
        return app(FieldReportService::class);
    }

    private function makeTicket(): Ticket
    {
        return Ticket::create([
            'customer_id' => 1, // crm_customers.id (cross-module, no FK)
            'title' => 'NVR utama mati total — rekaman CCTV berhenti',
            'priority' => 'critical',
            'reported_at' => '2026-06-10 09:15:00',
        ]);
    }

    /**
     * A SUBMITTED report ready for the customer's signature. Parts are
     * [item, qty] pairs; warehouse_id may be overridden to null to model the
     * technician who forgot to name the gudang.
     */
    private function makeSubmittedReport(array $parts = [], array $attributes = []): FieldReport
    {
        $report = FieldReport::create(array_merge([
            'ticket_id' => $this->makeTicket()->id,
            'report_date' => '2026-06-10',
            'technician_employee_id' => self::TECHNICIAN_ID,
            'warehouse_id' => $this->gudang->id,
            'findings' => 'PSU NVR gagal; 1 kamera dome lobi mati total.',
            'actions_taken' => 'Penggantian PSU dan 1 unit CCTV Dome 4MP.',
            'status' => FieldReportStatus::Submitted,
        ], $attributes));

        foreach ($parts as [$item, $qty]) {
            $report->parts()->create(['item_id' => $item->id, 'qty' => $qty]);
        }

        return $report->refresh();
    }

    public function test_acknowledging_a_report_with_parts_posts_the_stock_issue_in_the_same_transaction(): void
    {
        $this->receiveStock($this->gudang, $this->kamera, 30, 1850000, '2026-06-01');

        $report = $this->makeSubmittedReport([[$this->kamera, 1]]);

        $acknowledged = $this->reports()->acknowledge($report, 'Darto Prasetyo');

        $this->assertSame(FieldReportStatus::Acknowledged, $acknowledged->status);
        $this->assertSame('Darto Prasetyo', $acknowledged->customer_sign_name);
        $this->assertNotNull($acknowledged->customer_signed_at);

        // Exactly one issue, linked to the report, posted, dated on the VISIT.
        $issue = Issue::query()->where('field_report_id', $report->id)->sole();
        $this->assertSame(StockDocumentStatus::Posted, $issue->status);
        $this->assertSame($this->gudang->id, (int) $issue->warehouse_id);
        $this->assertSame('2026-06-10', $issue->issue_date->toDateString());
        $this->assertStringContainsString($report->code, $issue->purpose);
        $this->assertStringContainsString($report->ticket->code, $issue->purpose);

        // The acknowledged report answers with its bon (schema.js shows the link).
        $this->assertSame((int) $issue->id, (int) $acknowledged->issue?->id);

        // One line, valued at the warehouse moving average: 1 * 1.850.000.
        $line = $issue->items()->sole();
        $this->assertSame(1.0, (float) $line->qty);
        $this->assertSame(1850000.0, (float) $line->unit_cost);
        $this->assertSame(1850000.0, (float) $line->amount);

        // The camera actually left the shelf: 30 -> 29 @ unchanged average.
        $this->assertSame(29.0, $this->balanceQty($this->gudang, $this->kamera));
        $this->assertSame(1850000.0, $this->balanceAvg($this->gudang, $this->kamera));

        // No project on a service visit, so the cost is general opex:
        // Dr 6-4100 / Cr 1-1400, both legs Rp 1.850.000, no project cost row.
        $journal = $this->singleJournalFor('inventory_issue', (int) $issue->id);
        $this->assertPostedAndBalanced($journal, '2026-06-10');

        $lines = $this->linesByAccount($journal);
        $this->assertSame(['6-4100', '1-1400'], array_keys($lines));
        $this->assertSame(1850000.0, $lines['6-4100']['debit']);
        $this->assertSame(1850000.0, $lines['1-1400']['credit']);
        $this->assertNull($lines['6-4100']['project_id']);

        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_acknowledging_twice_is_refused_and_cannot_issue_twice(): void
    {
        $this->receiveStock($this->gudang, $this->kamera, 30, 1850000, '2026-06-01');

        $report = $this->makeSubmittedReport([[$this->kamera, 1]]);
        $this->reports()->acknowledge($report, 'Darto Prasetyo');

        try {
            $this->reports()->acknowledge($report->fresh(), 'Darto Prasetyo');
            $this->fail('Expected a LogicException for the second acknowledgement.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('must be submitted', $e->getMessage());
        }

        // One bon, one camera, one journal — nothing doubled.
        $this->assertSame(1, Issue::query()->where('field_report_id', $report->id)->count());
        $this->assertSame(29.0, $this->balanceQty($this->gudang, $this->kamera));
        $this->assertSame(1, Journal::query()->where('reference_type', 'inventory_issue')->count());
    }

    public function test_a_report_with_no_parts_acknowledges_exactly_as_before(): void
    {
        // No parts, and deliberately no warehouse either: a signature-only
        // visit must not be blocked by a field it does not need.
        $report = $this->makeSubmittedReport([], ['warehouse_id' => null]);

        $acknowledged = $this->reports()->acknowledge($report, 'Ns. Ratna Sari');

        $this->assertSame(FieldReportStatus::Acknowledged, $acknowledged->status);
        $this->assertSame('Ns. Ratna Sari', $acknowledged->customer_sign_name);
        $this->assertNotNull($acknowledged->customer_signed_at);

        $this->assertSame(0, Issue::query()->count());
        $this->assertSame(0, StockLedgerEntry::query()->count());
        $this->assertSame(0, Journal::query()->count());
    }

    public function test_parts_without_a_warehouse_refuse_the_acknowledgement_and_roll_it_back(): void
    {
        $this->receiveStock($this->gudang, $this->kamera, 30, 1850000, '2026-06-01');

        $report = $this->makeSubmittedReport([[$this->kamera, 1]], ['warehouse_id' => null]);

        try {
            $this->reports()->acknowledge($report, 'Darto Prasetyo');
            $this->fail('Expected a DomainException for the missing warehouse.');
        } catch (DomainException $e) {
            // Refusal, not a guess: there is no site-to-warehouse mapping in
            // the schema, so the message names the field to fill instead.
            $this->assertStringContainsString($report->code, $e->getMessage());
            $this->assertStringContainsString('gudang asalnya belum diisi', $e->getMessage());
        }

        $fresh = $report->fresh();
        $this->assertSame(FieldReportStatus::Submitted, $fresh->status);
        $this->assertNull($fresh->customer_sign_name);
        $this->assertNull($fresh->customer_signed_at);

        $this->assertSame(0, Issue::query()->count());
        $this->assertSame(30.0, $this->balanceQty($this->gudang, $this->kamera));
    }

    public function test_insufficient_stock_rolls_back_the_acknowledgement_and_the_signature(): void
    {
        // Only 1 camera on the shelf; the visit claims to have used 2.
        $this->receiveStock($this->gudang, $this->kamera, 1, 1850000, '2026-06-01');

        $report = $this->makeSubmittedReport([[$this->kamera, 2]]);

        try {
            $this->reports()->acknowledge($report, 'Darto Prasetyo');
            $this->fail('Expected a DomainException for insufficient stock.');
        } catch (DomainException $e) {
            // StockService's own refusal, verbatim — same rule as a hand bon.
            $this->assertStringContainsString('Stok tidak mencukupi', $e->getMessage());
        }

        // Everything rolled back together: signature, issue, stock, journal.
        $fresh = $report->fresh();
        $this->assertSame(FieldReportStatus::Submitted, $fresh->status);
        $this->assertNull($fresh->customer_sign_name);

        $this->assertSame(0, Issue::query()->count());
        $this->assertSame(1.0, $this->balanceQty($this->gudang, $this->kamera));
        $this->assertSame(0, StockLedgerEntry::query()->where('direction', 'out')->count());
        $this->assertSame(0, Journal::query()->where('reference_type', 'inventory_issue')->count());
    }

    public function test_a_closed_fiscal_period_rolls_back_the_acknowledgement_and_the_stock_movement(): void
    {
        $this->receiveStock($this->gudang, $this->kamera, 30, 1850000, '2026-05-20');

        FiscalPeriod::query()
            ->where('year', 2026)
            ->where('month', 6)
            ->update(['status' => 'closed']);

        $report = $this->makeSubmittedReport([[$this->kamera, 1]]); // visit dated 2026-06-10

        try {
            $this->reports()->acknowledge($report, 'Darto Prasetyo');
            $this->fail('Expected a LogicException for the closed fiscal period.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Periode fiskal 2026-06 sudah ditutup', $e->getMessage());
        }

        $fresh = $report->fresh();
        $this->assertSame(FieldReportStatus::Submitted, $fresh->status);
        $this->assertNull($fresh->customer_signed_at);

        $this->assertSame(0, Issue::query()->count());
        $this->assertSame(30.0, $this->balanceQty($this->gudang, $this->kamera));
        $this->assertSame(0, StockLedgerEntry::query()->where('direction', 'out')->count());
        $this->assertSame(0, Journal::query()->where('reference_type', 'inventory_issue')->count());
    }

    public function test_editing_the_parts_of_an_acknowledged_report_is_refused(): void
    {
        $this->receiveStock($this->gudang, $this->kamera, 30, 1850000, '2026-06-01');

        $report = $this->makeSubmittedReport([[$this->kamera, 1]]);
        $this->reports()->acknowledge($report, 'Darto Prasetyo');

        try {
            $this->reports()->update($report->fresh(), [
                'parts' => [['item_id' => $this->kamera->id, 'qty' => 5]],
            ]);
            $this->fail('Expected a LogicException: the issue already happened.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('can no longer be edited', $e->getMessage());
        }

        // The parts row still says 1 — matching the bon that was posted.
        $part = $report->fresh()->parts()->sole();
        $this->assertSame(1.0, (float) $part->qty);
        $this->assertSame(29.0, $this->balanceQty($this->gudang, $this->kamera));
    }

    public function test_a_draft_report_cannot_be_acknowledged(): void
    {
        $report = $this->makeSubmittedReport([], ['status' => FieldReportStatus::Draft]);

        try {
            $this->reports()->acknowledge($report, 'Darto Prasetyo');
            $this->fail('Expected a LogicException for a draft report.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('must be submitted', $e->getMessage());
        }

        $this->assertSame(FieldReportStatus::Draft, $report->fresh()->status);
    }
}
