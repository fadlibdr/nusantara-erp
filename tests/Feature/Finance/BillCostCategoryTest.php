<?php

namespace Tests\Feature\Finance;

use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Models\ProjectCost;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Which RAP bucket a vendor bill charges.
 *
 * It used to be derived from the source document alone — "the bill names a PO"
 * meant material — so a crane hired for Rp 180.000.000 through a services PO
 * (no item_id on any line, so no goods receipt and no stock sub-ledger row)
 * debited 5-1100 Beban Material Proyek and wrote fin_project_costs with
 * cost_category 'material'. reports/project-profitability then showed material
 * realisasi Rp 180 juta over budget and equipment Rp 180 juta under, on a
 * project that bought no extra material, while CostCategory's own docblock
 * promises these values line realisasi up against Estimation's budget lines.
 *
 * The derivation stays as the default — say nothing and nothing changes — and
 * an operator who knows what was bought can say so.
 */
class BillCostCategoryTest extends ErpTestCase
{
    use FinanceFixtures;

    private Vendor $vendor;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->vendor = $this->makeVendor();
        $this->project = $this->makeProject();
    }

    public function test_a_rental_po_bill_can_be_booked_to_the_equipment_bucket(): void
    {
        $po = $this->makePurchaseOrder($this->vendor, [
            'project_id' => $this->project->id,
            'subtotal' => 180000000,
            'dpp' => 180000000,
            'ppn_amount' => 19800000,
            'total' => 199800000,
        ]);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
            'cost_category' => 'equipment',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // 5-1400 Beban Alat, not 5-1100 Beban Material Proyek.
        $this->assertSame(180000000.0, $lines['5-1400']['debit']);
        $this->assertArrayNotHasKey('5-1100', $lines);

        $cost = ProjectCost::query()->where('reference_type', 'ap_bill')->sole();
        $this->assertSame('equipment', $cost->cost_category->value);
        $this->assertSame(180000000.0, (float) $cost->amount);
    }

    /**
     * Saying nothing keeps the old derivation, so nothing that used to work
     * moves: a PO bill is still material unless the operator says otherwise.
     */
    public function test_a_bill_that_states_no_bucket_still_derives_one_from_its_source(): void
    {
        $po = $this->makePurchaseOrder($this->vendor, ['project_id' => $this->project->id]);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertNull($bill->fresh()->cost_category);
        $this->assertSame(100000000.0, $lines['5-1100']['debit']);
        $this->assertSame('material', ProjectCost::query()->where('reference_type', 'ap_bill')->sole()->cost_category->value);
    }

    /** A manual project bill is overhead by default and can be re-bucketed too. */
    public function test_a_manual_project_bill_can_be_moved_out_of_overhead(): void
    {
        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'project_id' => $this->project->id,
            'bill_date' => '2026-03-10',
            'description' => 'Upah borongan pembesian',
            'dpp' => 60000000,
            'cost_category' => 'labor',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(60000000.0, $lines['5-1200']['debit']);
        $this->assertSame('labor', ProjectCost::query()->where('reference_type', 'ap_bill')->sole()->cost_category->value);
    }

    /** It is still editable while the bill is a draft. */
    public function test_the_bucket_can_be_corrected_on_a_draft(): void
    {
        $bill = $this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'project_id' => $this->project->id,
            'bill_date' => '2026-03-10',
            'description' => 'Sewa crane 50 ton',
            'dpp' => 180000000,
            'cost_category' => 'material',
        ]);

        $updated = $this->apBills()->update($bill, ['cost_category' => 'equipment']);

        $this->assertSame(CostCategory::Equipment, $updated->cost_category);
    }
}
