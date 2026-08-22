<?php

namespace Tests\Feature\Inventory;

use Illuminate\Database\QueryException;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * inv_issues.field_report_id is UNIQUE: one customer sign-off, one bon. The
 * business rule lives in FieldReportService::acknowledge() (a non-Submitted
 * report is refused), but lockForUpdate is a no-op on SQLite, so the schema
 * itself must be the last line against two concurrent acknowledgements both
 * issuing PM parts for the same report.
 */
class IssueFieldReportLinkTest extends ErpTestCase
{
    use InventoryFixtures;

    private Warehouse $pusat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
    }

    /** svc_field_reports.id is a cross-module reference — no FK to satisfy. */
    private function makeLinkedIssue(?int $fieldReportId): Issue
    {
        return Issue::create([
            'warehouse_id' => $this->pusat->id,
            'field_report_id' => $fieldReportId,
            'issue_date' => '2026-06-10',
            'purpose' => 'Suku cadang servis PM/2026/VI/0002',
            'status' => StockDocumentStatus::Draft,
        ]);
    }

    public function test_the_database_refuses_a_second_issue_for_the_same_field_report(): void
    {
        $this->makeLinkedIssue(42);

        $this->expectException(QueryException::class);

        $this->makeLinkedIssue(42);
    }

    public function test_hand_raised_bons_carry_no_link_and_may_coexist_freely(): void
    {
        // A unique index still admits any number of NULLs — ordinary bons are
        // untouched by the field-report bridge.
        $this->makeLinkedIssue(null);
        $this->makeLinkedIssue(null);

        $this->assertSame(2, Issue::query()->whereNull('field_report_id')->count());
    }
}
