<?php

namespace Tests\Feature\Crm;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Enums\ChangeOrderType;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Services\ContractChangeOrderService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Pekerjaan tambah-kurang.
 *
 * An approved contract is permanently immutable, which is correct — its value is
 * the basis of every termin invoice raised against it. The consequence was that
 * legitimately added or removed scope had no path at all, and the only
 * workarounds were a second unrelated contract or editing the database by hand.
 *
 * What matters here is that the contract's history survives the amendment: what
 * was signed stays readable, and a reduction can never take the contract below
 * what has already been invoiced against it.
 */
class ContractChangeOrderTest extends ErpTestCase
{
    use FinanceFixtures;

    private ContractChangeOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->service = app(ContractChangeOrderService::class);
    }

    private function approvedContract(float $value = 1_000_000_000)
    {
        $contract = $this->makeContract($this->makeCustomer(), ['value' => $value]);
        $contract->forceFill([
            'status' => DocumentStatus::Approved,
            'value' => $value,
            'ppn_amount' => round($value * 0.11, 2),
            'total_with_ppn' => round($value * 1.11, 2),
        ])->save();

        return $contract->refresh();
    }

    private function changeOrder($contract, float $change): ContractChangeOrder
    {
        return $this->service->create([
            'contract_id' => $contract->id,
            'change_date' => '2026-06-01',
            'title' => $change >= 0 ? 'Tambah pekerjaan ME lantai 9' : 'Kurang pekerjaan lansekap',
            'value_change' => $change,
            'reason' => 'permintaan_pelanggan',
        ]);
    }

    private function approveOrder(ContractChangeOrder $order): ContractChangeOrder
    {
        // Prepared by one person, agreed by another: a change order that moves
        // the contract value is precisely the document maker-checker exists for.
        $order->submit($this->financeUser());

        return $this->service->approve($order->refresh(), $this->financeApprover());
    }

    public function test_added_scope_raises_the_contract_value_on_approval(): void
    {
        $contract = $this->approvedContract();
        $order = $this->changeOrder($contract, 150_000_000);

        $this->assertEqualsWithDelta(1_000_000_000, (float) $contract->refresh()->value, 0.01, 'nothing moves before approval');

        $this->approveOrder($order);

        $this->assertEqualsWithDelta(1_150_000_000, (float) $contract->refresh()->value, 0.01);
    }

    /** Removed scope is as ordinary as added scope, and is the same document. */
    public function test_removed_scope_lowers_the_contract_value(): void
    {
        $contract = $this->approvedContract();

        $this->approveOrder($this->changeOrder($contract, -80_000_000));

        $this->assertEqualsWithDelta(920_000_000, (float) $contract->refresh()->value, 0.01);
    }

    /** "What did we agree to" must survive "what is it worth now". */
    public function test_the_signed_value_is_preserved(): void
    {
        $contract = $this->approvedContract();

        $this->approveOrder($this->changeOrder($contract, 150_000_000));
        $this->approveOrder($this->changeOrder($contract->refresh(), 60_000_000));

        $contract->refresh();

        $this->assertEqualsWithDelta(1_000_000_000, (float) $contract->original_value, 0.01, 'the signed value must stick');
        $this->assertEqualsWithDelta(1_210_000_000, (float) $contract->value, 0.01);
    }

    public function test_ppn_follows_the_new_contract_value(): void
    {
        $contract = $this->approvedContract();

        $this->approveOrder($this->changeOrder($contract, 100_000_000));
        $contract->refresh();

        $this->assertEqualsWithDelta(121_000_000, (float) $contract->ppn_amount, 0.01);
        $this->assertEqualsWithDelta(1_221_000_000, (float) $contract->total_with_ppn, 0.01);
    }

    /**
     * THE GUARD THAT MATTERS. A contract worth less than the sum of its own
     * approved invoices is a state no report can present and no auditor accepts.
     */
    public function test_a_reduction_cannot_take_the_contract_below_what_was_billed(): void
    {
        $contract = $this->approvedContract(1_000_000_000);

        $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $contract->customer_id,
            'contract_id' => $contract->id,
            'description' => 'Termin 1',
            'dpp' => 600_000_000,
            'ppn_rate' => 0.0,
            'invoice_date' => '2026-03-15',
        ]));

        $order = $this->changeOrder($contract, -500_000_000);
        $order->submit($this->financeUser());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah ditagihkan/');

        $this->service->approve($order->refresh(), $this->financeApprover());
    }

    public function test_a_rejected_change_order_leaves_the_contract_alone(): void
    {
        $contract = $this->approvedContract();
        $order = $this->changeOrder($contract, 150_000_000);
        $order->submit($this->financeUser());

        $this->service->reject($order->refresh(), $this->financeUser(), 'Belum disepakati pelanggan');

        $this->assertEqualsWithDelta(1_000_000_000, (float) $contract->refresh()->value, 0.01);
        $this->assertNull($contract->original_value);
    }

    /**
     * A draft contract should be EDITED. A change order against it would be a
     * second way to set the same number.
     */
    public function test_a_contract_that_is_not_approved_cannot_be_amended(): void
    {
        // makeContract() defaults to Approved, so a draft has to be asked for.
        $contract = $this->makeContract($this->makeCustomer(), [
            'value' => 500_000_000,
            'status' => DocumentStatus::Draft,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/hanya berlaku atas kontrak yang sudah disetujui/');

        $this->changeOrder($contract, 100_000_000);
    }

    public function test_an_approved_change_order_can_no_longer_be_edited(): void
    {
        $contract = $this->approvedContract();
        $order = $this->approveOrder($this->changeOrder($contract, 100_000_000));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak dapat diubah/');

        $this->service->update($order->refresh(), ['value_change' => 999_000_000]);
    }

    /** Existing termins are never touched — re-spreading would restate billing. */
    public function test_existing_termins_are_untouched(): void
    {
        $contract = $this->approvedContract();
        $before = $contract->termins()->orderBy('id')->get()
            ->map(fn ($t) => [$t->id, (float) $t->amount, (float) $t->percent])->all();

        $this->approveOrder($this->changeOrder($contract, 200_000_000));

        $after = $contract->refresh()->termins()->orderBy('id')->get()
            ->map(fn ($t) => [$t->id, (float) $t->amount, (float) $t->percent])->all();

        $this->assertSame($before, $after);
    }

    public function test_the_summary_shows_how_the_value_got_here(): void
    {
        $contract = $this->approvedContract();

        $this->approveOrder($this->changeOrder($contract, 150_000_000));
        $this->approveOrder($this->changeOrder($contract->refresh(), -50_000_000));

        $summary = $this->service->summaryFor($contract->refresh());

        $this->assertEqualsWithDelta(1_000_000_000, $summary['original_value'], 0.01);
        $this->assertEqualsWithDelta(1_100_000_000, $summary['current_value'], 0.01);
        $this->assertEqualsWithDelta(100_000_000, $summary['net_change'], 0.01);
        $this->assertEqualsWithDelta(150_000_000, $summary['additions'], 0.01);
        $this->assertEqualsWithDelta(-50_000_000, $summary['reductions'], 0.01);
        $this->assertSame(2, $summary['change_order_count']);
    }

    /**
     * Temuan #74. prj_projects.contract_value is copied ONCE when the project
     * is created from the contract, and the project workspace tiles ("Nilai
     * kontrak", "Retensi ditahan") read that copy. If approval only moves
     * crm_contracts.value, the project team and finance see two different
     * numbers for the most important figure of the project.
     */
    public function test_approval_moves_the_projects_copy_of_the_contract_value(): void
    {
        $contract = $this->approvedContract();
        $project = $this->makeProject([
            'contract_id' => $contract->id,
            'contract_value' => 1_000_000_000,
        ]);

        $this->approveOrder($this->changeOrder($contract, 150_000_000));

        $this->assertEqualsWithDelta(1_150_000_000, (float) $project->refresh()->contract_value, 0.01);
    }

    public function test_a_rejected_change_order_leaves_the_projects_copy_alone(): void
    {
        $contract = $this->approvedContract();
        $project = $this->makeProject([
            'contract_id' => $contract->id,
            'contract_value' => 1_000_000_000,
        ]);

        $order = $this->changeOrder($contract, 150_000_000);
        $order->submit($this->financeUser());
        $this->service->reject($order->refresh(), $this->financeApprover(), 'Belum disepakati pelanggan');

        $this->assertEqualsWithDelta(1_000_000_000, (float) $project->refresh()->contract_value, 0.01);
    }

    /**
     * Temuan #61. Without a type of its own, a price escalation on a multi-year
     * contract enters the system disguised as "pekerjaan tambah" — a wrong
     * meaning an auditor reads very differently. The default keeps every
     * existing and untyped change order meaning what it always meant.
     */
    public function test_a_change_order_is_tambah_kurang_unless_said_otherwise(): void
    {
        $order = $this->changeOrder($this->approvedContract(), 150_000_000);

        $this->assertSame(ChangeOrderType::TambahKurang, $order->refresh()->change_type);
    }

    public function test_a_price_escalation_is_recorded_as_its_own_kind(): void
    {
        $contract = $this->approvedContract();

        $order = $this->service->create([
            'contract_id' => $contract->id,
            'change_date' => '2026-06-01',
            'title' => 'Eskalasi harga tahun ke-2 (indeks BPS)',
            'change_type' => 'eskalasi_harga',
            'value_change' => 45_000_000,
        ]);

        $this->assertSame(ChangeOrderType::EskalasiHarga, $order->refresh()->change_type);

        // The value still moves through the one CCO path on approval — only
        // the audit meaning differs.
        $this->approveOrder($order);
        $this->assertEqualsWithDelta(1_045_000_000, (float) $contract->refresh()->value, 0.01);
    }

    public function test_the_endpoint_refuses_an_unknown_change_type(): void
    {
        $contract = $this->approvedContract();

        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/contract-change-orders', [
                'contract_id' => $contract->id,
                'change_date' => '2026-06-01',
                'title' => 'Jenis tidak dikenal',
                'change_type' => 'force_majeure',
                'value_change' => 10_000_000,
            ])
            ->assertStatus(422);
    }

    /** A change order that changes nothing is a note, not an amendment. */
    public function test_the_endpoint_refuses_a_zero_change(): void
    {
        $contract = $this->approvedContract();

        $this->actingAs($this->adminUser())
            ->postJson('/api/crm/contract-change-orders', [
                'contract_id' => $contract->id,
                'change_date' => '2026-06-01',
                'title' => 'Tidak mengubah apa pun',
                'value_change' => 0,
            ])
            ->assertStatus(422);
    }
}
