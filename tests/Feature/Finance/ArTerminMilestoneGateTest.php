<?php

namespace Tests\Feature\Finance;

use Illuminate\Validation\ValidationException;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Finance\Models\ArInvoice;
use Modules\Projects\Models\Milestone;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Temuan #32 (paruh kedua) — menagih termin yang syaratnya belum tercapai.
 *
 * createFromTermin checked billed/duplicate/contract-approved and never once
 * read prj_milestones, so Finance could invoice 'Progress 80%' while the
 * certified progress stood at 55% — the owner's MK rejects the invoice, the
 * BAP never comes, and the relationship takes the damage. A hard block would
 * be wrong the other way: legitimate deviations (a negotiated early billing,
 * a contract addendum) must stay possible.
 *
 * So the violation becomes an explicit confirmation, the confirmResubmit
 * pattern #72 built for zero-cost GRNs: a 422 on `termin_id` until the payload
 * carries confirm_unachieved_milestone, and the confirmed invoice RECORDS the
 * fact in its description so the audit trail shows who billed past the
 * condition knowingly.
 */
class ArTerminMilestoneGateTest extends ErpTestCase
{
    use FinanceFixtures;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->contract = $this->makeContract($this->makeCustomer());
    }

    // -------------------------------------------------------------- fixtures

    private function termin(int $no = 3, string $name = 'Progress 80%'): ContractTermin
    {
        return $this->makeTermin($this->contract, $no, $name, 30, 3_000_000_000);
    }

    private function milestone(ContractTermin $termin, ?string $achievedOn, string $name = 'Progres fisik 80%'): Milestone
    {
        $project = Project::query()->firstOrCreate(
            ['contract_id' => $this->contract->id],
            ['name' => 'Proyek '.$this->contract->code, 'type' => 'construction', 'status' => 'active'],
        );

        return Milestone::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'due_date' => '2026-09-30',
            'achieved_date' => $achievedOn,
            'termin_id' => $termin->id,
        ]);
    }

    // --------------------------------------------------------------- the gate

    public function test_billing_a_termin_whose_milestone_is_unachieved_is_refused(): void
    {
        $termin = $this->termin();
        $this->milestone($termin, null);

        try {
            $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-08-01']);
            $this->fail('An unachieved billing condition must demand explicit confirmation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('termin_id', $e->errors());
            $this->assertStringContainsString('belum tercapai', $e->errors()['termin_id'][0]);
            $this->assertStringContainsString('Progres fisik 80%', $e->errors()['termin_id'][0]);
        }

        $this->assertSame(0, ArInvoice::query()->count(), 'nothing may survive the refusal');
        $this->assertNull($termin->refresh()->billed_at);
    }

    /** The SPA's confirm flow needs the 422 keyed on termin_id — that is its contract. */
    public function test_the_endpoint_refuses_with_422_on_termin_id(): void
    {
        $termin = $this->termin();
        $this->milestone($termin, null);

        $this->actingAs($this->adminUser())
            ->postJson('/api/finance/ar-invoices', ['termin_id' => $termin->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('termin_id');

        $this->assertSame(0, ArInvoice::query()->count());
    }

    // ----------------------------------------------------- confirmed override

    /**
     * Recorded, not blocking: with the confirmation flag the invoice is built,
     * and the description carries the fact permanently — the deviation is a
     * decision someone made, readable on the document the customer receives.
     */
    public function test_confirming_bills_anyway_and_records_it_on_the_invoice(): void
    {
        $termin = $this->termin();
        $this->milestone($termin, null);

        $invoice = $this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-08-01',
            'confirm_unachieved_milestone' => true,
        ]);

        $this->assertEqualsWithDelta(3_000_000_000, (float) $invoice->dpp, 0.01);
        $this->assertStringContainsString('belum tercapai', $invoice->description);
        $this->assertStringContainsString('Progres fisik 80%', $invoice->description);
    }

    // ------------------------------------------------------------ no gate when

    public function test_an_achieved_milestone_needs_no_confirmation(): void
    {
        $termin = $this->termin();
        $this->milestone($termin, '2026-07-15');

        $invoice = $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-08-01']);

        $this->assertStringNotContainsString('belum tercapai', $invoice->description, 'nothing was overridden');
    }

    /**
     * Several milestones may release one termin; the FIRST achieved one makes
     * it billable (the same rule TerminBillingService applies to the queue).
     */
    public function test_one_achieved_milestone_among_several_lifts_the_gate(): void
    {
        $termin = $this->termin();
        $this->milestone($termin, null, 'Progres fisik 80% — struktur');
        $this->milestone($termin, '2026-07-20', 'Progres fisik 80% — arsitektur');

        $invoice = $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-08-01']);

        $this->assertStringNotContainsString('belum tercapai', $invoice->description);
    }

    /** A calendar termin (maintenance quarter) has no milestone and no gate. */
    public function test_a_termin_without_milestones_needs_no_confirmation(): void
    {
        $termin = $this->termin(4, 'Triwulan II 25%');

        $invoice = $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-08-01']);

        $this->assertEqualsWithDelta(3_000_000_000, (float) $invoice->dpp, 0.01);
        $this->assertStringNotContainsString('belum tercapai', $invoice->description);
    }
}
