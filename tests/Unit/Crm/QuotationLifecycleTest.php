<?php

namespace Tests\Unit\Crm;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Quotation;
use Tests\ErpTestCase;

/**
 * Quotation lifecycle: draft -> submitted -> approved/rejected (Approvable),
 * then won (opens a draft contract) or lost. The outcome may only be decided
 * once; a revision reopens the quotation as a draft with the counter bumped.
 */
class QuotationLifecycleTest extends ErpTestCase
{
    use CrmFixtures;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->makeCustomer();
    }

    private function makeQuotation(array $data = []): Quotation
    {
        return $this->quotations()->create(array_merge([
            'customer_id' => $this->customer->id,
            'title' => 'Penawaran pembangunan gedung kantor',
            'scope_type' => 'construction',
            'items' => [
                ['description' => 'Pekerjaan struktur', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 40000000000],
            ],
        ], $data));
    }

    private function approvedQuotation(): Quotation
    {
        $quotation = $this->makeQuotation();
        $quotation->submit();
        $quotation->approve($this->makeUser());

        return $quotation;
    }

    // ------------------------------------------------------------- approvable

    public function test_a_new_quotation_starts_as_a_draft_with_revision_zero(): void
    {
        $quotation = $this->makeQuotation();

        $this->assertSame(DocumentStatus::Draft, $quotation->status);
        $this->assertSame(0, (int) $quotation->revision);
        $this->assertNull($quotation->won_at);
        $this->assertNull($quotation->lost_at);
        $this->assertStringStartsWith('QTN/', $quotation->code);
    }

    public function test_submit_then_approve_walks_the_document_through_the_approval_states(): void
    {
        $quotation = $this->makeQuotation();

        // Submitted by the estimator, approved by the sales manager — the
        // trait refuses the two being the same person.
        $quotation->submit($this->makeUser());
        $this->assertSame(DocumentStatus::Submitted, $quotation->refresh()->status);

        $quotation->approve($this->makeUser('manajer-sales@test.local'), 'Sesuai pagu owner.');
        $this->assertSame(DocumentStatus::Approved, $quotation->refresh()->status);

        $this->assertSame(
            ['submitted', 'approved'],
            $quotation->approvals()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_reject_sends_the_document_back_to_an_editable_state(): void
    {
        $quotation = $this->makeQuotation();
        $quotation->submit();
        $quotation->reject($this->makeUser(), 'Harga terlalu tinggi.');

        $this->assertSame(DocumentStatus::Rejected, $quotation->refresh()->status);
        $this->assertTrue($quotation->status->isEditable());
    }

    public function test_approving_a_draft_throws_and_leaves_the_status_untouched(): void
    {
        $quotation = $this->makeQuotation();

        try {
            $quotation->approve($this->makeUser());
            $this->fail('Expected LogicException when approving a draft.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('while status is draft', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Draft, Quotation::query()->find($quotation->id)->status);
    }

    // -------------------------------------------------------------- won / lost

    public function test_marking_won_stamps_won_at_and_opens_a_draft_contract(): void
    {
        $quotation = $this->approvedQuotation();

        $contract = $this->quotations()->markWon($quotation);

        $this->assertNotNull($quotation->refresh()->won_at);
        $this->assertInstanceOf(Contract::class, $contract);
        $this->assertSame(DocumentStatus::Draft, $contract->status);
        $this->assertSame($quotation->id, (int) $contract->quotation_id);
    }

    public function test_only_an_approved_quotation_can_be_marked_won(): void
    {
        $quotation = $this->makeQuotation();
        $quotation->submit();

        try {
            $this->quotations()->markWon($quotation);
            $this->fail('Expected LogicException when winning a submitted quotation.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Only an approved quotation', $e->getMessage());
        }

        $this->assertNull(Quotation::query()->find($quotation->id)->won_at);
        $this->assertSame(0, Contract::query()->count());
    }

    public function test_marking_lost_stamps_lost_at_with_the_reason_and_closes_the_document(): void
    {
        $quotation = $this->approvedQuotation();

        $this->quotations()->markLost($quotation, 'Kalah harga dari kompetitor.');

        $quotation->refresh();

        $this->assertNotNull($quotation->lost_at);
        $this->assertSame('Kalah harga dari kompetitor.', $quotation->lost_reason);
        $this->assertSame(DocumentStatus::Closed, $quotation->status);
    }

    public function test_a_won_quotation_cannot_also_be_marked_lost(): void
    {
        $quotation = $this->approvedQuotation();
        $this->quotations()->markWon($quotation);

        try {
            $this->quotations()->markLost($quotation, 'Berubah pikiran.');
            $this->fail('Expected LogicException when losing a won quotation.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('outcome has already been decided', $e->getMessage());
        }

        $fresh = Quotation::query()->findOrFail($quotation->id);

        $this->assertNull($fresh->lost_at);
        $this->assertNull($fresh->lost_reason);
        $this->assertNotNull($fresh->won_at);
        $this->assertSame(DocumentStatus::Approved, $fresh->status);
    }

    public function test_a_lost_quotation_cannot_be_marked_won_and_no_contract_appears(): void
    {
        $quotation = $this->approvedQuotation();
        $this->quotations()->markLost($quotation, 'Proyek ditunda owner.');

        try {
            $this->quotations()->markWon($quotation);
            $this->fail('Expected LogicException when winning a lost quotation.');
        } catch (LogicException $e) {
            // markLost closes the document, so the status guard fires first.
            $this->assertStringContainsString('Only an approved quotation', $e->getMessage());
        }

        $this->assertNull(Quotation::query()->find($quotation->id)->won_at);
        $this->assertSame(0, Contract::query()->count());
    }

    public function test_winning_twice_throws_and_creates_only_one_contract(): void
    {
        $quotation = $this->approvedQuotation();
        $this->quotations()->markWon($quotation);

        try {
            $this->quotations()->markWon($quotation->refresh());
            $this->fail('Expected LogicException when winning twice.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('outcome has already been decided', $e->getMessage());
        }

        $this->assertSame(1, Contract::query()->count());
    }

    // ---------------------------------------------------------------- revision

    public function test_revise_bumps_the_revision_number_and_reopens_the_draft(): void
    {
        $quotation = $this->approvedQuotation();

        $this->quotations()->revise($quotation);

        $quotation->refresh();

        // revisi 0 -> 1, status kembali ke draft agar baris bisa diedit
        $this->assertSame(1, (int) $quotation->revision);
        $this->assertSame(DocumentStatus::Draft, $quotation->status);
    }

    public function test_revise_keeps_the_lines_and_the_totals(): void
    {
        $quotation = $this->approvedQuotation();

        $this->quotations()->revise($quotation);

        $quotation->refresh();

        $this->assertSame(1, $quotation->items()->count());
        $this->assertSame(40000000000.0, (float) $quotation->subtotal);
        // 40.000.000.000 * 11 / 100 = 4.400.000.000 ; total = 44.400.000.000
        $this->assertSame(4400000000.0, (float) $quotation->ppn_amount);
        $this->assertSame(44400000000.0, (float) $quotation->total);
    }

    public function test_revise_clears_the_lost_stamp_so_the_deal_can_be_reopened(): void
    {
        $quotation = $this->approvedQuotation();
        $this->quotations()->markLost($quotation, 'Owner minta revisi lingkup.');

        $this->quotations()->revise($quotation);

        $quotation->refresh();

        $this->assertNull($quotation->lost_at);
        $this->assertNull($quotation->lost_reason);
        $this->assertSame(1, (int) $quotation->revision);
        $this->assertSame(DocumentStatus::Draft, $quotation->status);
    }

    public function test_revising_twice_reaches_revision_two(): void
    {
        $quotation = $this->makeQuotation();

        $this->quotations()->revise($quotation);
        $this->quotations()->revise($quotation->refresh());

        $this->assertSame(2, (int) $quotation->refresh()->revision);
    }

    public function test_a_won_quotation_cannot_be_revised(): void
    {
        $quotation = $this->approvedQuotation();
        $this->quotations()->markWon($quotation);

        try {
            $this->quotations()->revise($quotation->refresh());
            $this->fail('Expected LogicException when revising a won quotation.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('revise via the contract instead', $e->getMessage());
        }

        $fresh = Quotation::query()->findOrFail($quotation->id);

        $this->assertSame(0, (int) $fresh->revision);
        $this->assertSame(DocumentStatus::Approved, $fresh->status);
    }
}
