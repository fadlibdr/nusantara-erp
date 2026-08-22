<?php

namespace Tests\Feature\Crm;

use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Services\LeadService;
use Modules\Crm\Services\QuotationService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Temuan #58 — lead tidak mengikuti nasib penawarannya.
 *
 * Lead status was purely manual: markWon/markLost never touched the linked
 * lead, so the pipeline froze at "Penawaran Dikirim" unless somebody
 * remembered to edit it — win-rate per sales was never right. And because a
 * quotation REQUIRES an existing customer, prospect data was typed twice:
 * once as the lead, once again as the customer.
 */
class LeadQuotationOutcomeTest extends ErpTestCase
{
    use FinanceFixtures;

    private QuotationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(QuotationService::class);
    }

    private function makeLead(array $attributes = []): Lead
    {
        return Lead::query()->create(array_merge([
            'name' => 'Budi Santoso',
            'company_name' => 'PT Maju Bersama Konstruksi',
            'phone' => '0811223344',
            'email' => 'budi@majubersama.co.id',
            'status' => LeadStatus::Proposal,
        ], $attributes));
    }

    private function approvedQuotation(?Lead $lead = null): Quotation
    {
        $quotation = Quotation::query()->create([
            'customer_id' => $this->makeCustomer()->id,
            'lead_id' => $lead?->id,
            'title' => 'Instalasi ELV Gedung Kantor',
            'scope_type' => 'system_integration',
        ]);

        $quotation->forceFill([
            'status' => DocumentStatus::Approved,
            'dpp' => 750_000_000,
        ])->save();

        return $quotation->refresh();
    }

    public function test_marking_won_wins_the_linked_lead(): void
    {
        $lead = $this->makeLead();

        $this->service->markWon($this->approvedQuotation($lead));

        $this->assertSame(LeadStatus::Won, $lead->refresh()->status);
    }

    public function test_marking_lost_loses_the_linked_lead(): void
    {
        $lead = $this->makeLead();

        $this->service->markLost($this->approvedQuotation($lead), 'Kalah harga');

        $this->assertSame(LeadStatus::Lost, $lead->refresh()->status);
    }

    /**
     * A lead whose customer already came out of one won quotation must not be
     * demoted when a LATER quotation for the same lead is lost.
     */
    public function test_a_lead_that_already_won_stays_won_when_another_quotation_is_lost(): void
    {
        $lead = $this->makeLead();

        $this->service->markWon($this->approvedQuotation($lead));
        $this->service->markLost($this->approvedQuotation($lead), 'Paket kedua batal');

        $this->assertSame(LeadStatus::Won, $lead->refresh()->status);
    }

    public function test_a_quotation_without_a_lead_still_decides_cleanly(): void
    {
        $contract = $this->service->markWon($this->approvedQuotation());

        $this->assertNotNull($contract->id);
    }

    // ------------------------------------------------------- jadikan pelanggan

    /**
     * The idempotency check used to read customer_id off the CALLER'S instance,
     * outside the transaction — so two instances of one lead, converted in
     * sequence (the double-click shape), produced TWO customer rows, the second
     * overwriting crm_leads.customer_id and orphaning the first CUST- row:
     * exactly the hand-merge duplicate the comment promises cannot happen.
     */
    public function test_two_stale_instances_of_one_lead_convert_to_one_customer(): void
    {
        $lead = $this->makeLead(['status' => LeadStatus::Won]);

        $first = Lead::query()->findOrFail($lead->id);
        $second = Lead::query()->findOrFail($lead->id);

        $service = app(LeadService::class);
        $a = $service->convertToCustomer($first);
        $b = $service->convertToCustomer($second);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($a->id, (int) $lead->fresh()->customer_id);
    }

    public function test_converting_a_lead_creates_the_customer_from_its_fields(): void
    {
        $lead = $this->makeLead(['status' => LeadStatus::Won]);

        $this->actingAs($this->adminUser())
            ->postJson("/api/crm/leads/{$lead->id}/convert-to-customer")
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'PT Maju Bersama Konstruksi')
            ->assertJsonPath('data.pic_name', 'Budi Santoso')
            ->assertJsonPath('data.phone', '0811223344')
            ->assertJsonPath('data.email', 'budi@majubersama.co.id');

        $this->assertNotNull($lead->refresh()->customer_id, 'the lead must remember its customer');
    }

    /** The second click returns the same customer — never a CUST- duplicate. */
    public function test_converting_twice_does_not_duplicate_the_customer(): void
    {
        $lead = $this->makeLead(['status' => LeadStatus::Won]);
        $admin = $this->adminUser();

        $this->actingAs($admin)->postJson("/api/crm/leads/{$lead->id}/convert-to-customer")->assertStatus(201);
        $before = Customer::query()->count();

        $this->actingAs($admin)
            ->postJson("/api/crm/leads/{$lead->id}/convert-to-customer")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $lead->refresh()->customer_id);

        $this->assertSame($before, Customer::query()->count());
    }

    /** A lead without a company converts under the contact's own name. */
    public function test_a_personal_lead_converts_under_the_contact_name(): void
    {
        $lead = $this->makeLead(['company_name' => null, 'status' => LeadStatus::Won]);

        $this->actingAs($this->adminUser())
            ->postJson("/api/crm/leads/{$lead->id}/convert-to-customer")
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Budi Santoso');
    }

    // ---------------------------------------------------------- follow-up date

    public function test_the_follow_up_date_rides_the_lead_endpoints(): void
    {
        $lead = $this->makeLead();

        $this->actingAs($this->adminUser())
            ->putJson("/api/crm/leads/{$lead->id}", ['next_follow_up_at' => '2026-08-20'])
            ->assertStatus(200)
            ->assertJsonPath('data.next_follow_up_at', '2026-08-20');
    }
}
