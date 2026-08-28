<?php

namespace Tests\Feature\Crm;

use LogicException;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Services\ContractChangeOrderService;
use Modules\Crm\Services\CrmFormService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Addendum waktu (P0-B) — a change order of type 'waktu' that extends (or
 * shortens) the contract, in days.
 *
 * The invariants under test:
 *
 *  - value_change is exactly 0 — time and money never move on the same sheet;
 *  - new_end_date is COMPUTED at approval from the contract's CURRENT end_date,
 *    never input — so sequential addenda stack instead of both adding to the
 *    signing-day date;
 *  - crm_contracts.original_end_date is written once, by the first approved
 *    time addendum, mirroring original_value — "when did we promise to finish"
 *    must survive "when will we finish now";
 *  - the project's copy of the deadline moves through ProjectService, and a
 *    project already handed over (masa pemeliharaan / ditutup) refuses the
 *    addendum by name — extending a delivered job is a different instrument;
 *  - a rejected addendum moves nothing.
 */
class ContractTimeAddendumTest extends ErpTestCase
{
    use FinanceFixtures;

    private ContractChangeOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ContractChangeOrderService::class);
    }

    /** An approved contract with a real execution window. */
    private function timedContract(array $attributes = []): Contract
    {
        return $this->makeContract($this->makeCustomer(), array_merge([
            'start_date' => '2026-09-01',
            'end_date' => '2027-07-31',
        ], $attributes));
    }

    private function timeAddendum(Contract $contract, int $days, array $overrides = []): ContractChangeOrder
    {
        return $this->service->create(array_merge([
            'contract_id' => $contract->id,
            'change_date' => '2027-06-01',
            'title' => $days >= 0 ? 'Perpanjangan waktu — curah hujan ekstrem' : 'Pengurangan waktu — percepatan',
            'change_type' => 'waktu',
            'days_change' => $days,
            'value_change' => 0,
            'reason' => 'kondisi_lapangan',
        ], $overrides));
    }

    private function approveOrder(ContractChangeOrder $order): ContractChangeOrder
    {
        $order->submit($this->financeUser());

        return $this->service->approve($order->refresh(), $this->financeApprover());
    }

    // ------------------------------------------------------------ validation

    public function test_a_time_addendum_refuses_a_nonzero_value_change(): void
    {
        $contract = $this->timedContract();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/wajib 0/');

        $this->timeAddendum($contract, 14, ['value_change' => 50_000_000]);
    }

    public function test_a_time_addendum_without_days_is_not_a_change(): void
    {
        $contract = $this->timedContract();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/days_change/');

        $this->timeAddendum($contract, 0);
    }

    /** new_end_date is derived at approval — an input value would let two pending addenda both promise dates. */
    public function test_new_end_date_is_computed_never_input(): void
    {
        $contract = $this->timedContract();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/dihitung/');

        $this->timeAddendum($contract, 14, ['new_end_date' => '2027-12-31']);
    }

    public function test_days_change_belongs_to_time_addenda_only(): void
    {
        $contract = $this->timedContract();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/addendum waktu/');

        $this->service->create([
            'contract_id' => $contract->id,
            'change_date' => '2027-06-01',
            'title' => 'Tambah pekerjaan ME',
            'value_change' => 100_000_000,
            'days_change' => 14,
        ]);
    }

    public function test_a_contract_without_an_end_date_has_no_basis_to_shift(): void
    {
        $contract = $this->timedContract(['end_date' => null]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tanggal selesai/');

        $this->timeAddendum($contract, 14);
    }

    public function test_the_endpoint_refuses_a_nonzero_value_and_an_input_new_end_date(): void
    {
        $contract = $this->timedContract();
        $admin = $this->adminUser();

        $base = [
            'contract_id' => $contract->id,
            'change_date' => '2027-06-01',
            'title' => 'Perpanjangan waktu',
            'change_type' => 'waktu',
            'days_change' => 14,
        ];

        $this->actingAs($admin)
            ->postJson('/api/crm/contract-change-orders', $base + ['value_change' => 25_000_000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value_change');

        $this->actingAs($admin)
            ->postJson('/api/crm/contract-change-orders', $base + ['new_end_date' => '2027-12-31'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('new_end_date');

        $this->actingAs($admin)
            ->postJson('/api/crm/contract-change-orders', [
                'contract_id' => $contract->id,
                'change_date' => '2027-06-01',
                'title' => 'Hari pada CCO nilai',
                'value_change' => 100_000_000,
                'days_change' => 14,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('days_change');
    }

    // -------------------------------------------------------------- approval

    public function test_approval_shifts_contract_and_project_dates_and_computes_new_end_date(): void
    {
        $contract = $this->timedContract();
        $project = $this->makeProject([
            'contract_id' => $contract->id,
            'start_date' => '2026-09-01',
            'end_date' => '2027-07-31',
        ]);

        $order = $this->timeAddendum($contract, 14);

        $this->assertNull($order->refresh()->new_end_date, 'a draft promises no date — two pending addenda would both look authoritative');
        $this->assertSame('2027-07-31', $contract->refresh()->end_date->toDateString(), 'nothing moves before approval');

        $this->approveOrder($order);
        $contract->refresh();

        $this->assertSame('2027-08-14', $order->refresh()->new_end_date->toDateString(), 'computed by the service, stored for the printed register');
        $this->assertSame('2027-08-14', $contract->end_date->toDateString());
        $this->assertSame('2027-07-31', $contract->original_end_date->toDateString(), 'what was signed must stay readable');
        $this->assertSame('2027-08-14', $project->refresh()->end_date->toDateString(), 'the project copy of the deadline moves with the contract');
    }

    /** SEQUENTIAL addenda stack: the second builds on the ALREADY-SHIFTED date. */
    public function test_sequential_addenda_stack_and_original_end_date_is_written_once(): void
    {
        $contract = $this->timedContract();

        $this->approveOrder($this->timeAddendum($contract, 14));
        $second = $this->approveOrder($this->timeAddendum($contract->refresh(), 7, ['change_date' => '2027-07-01']));

        $contract->refresh();

        $this->assertSame('2027-08-21', $second->refresh()->new_end_date->toDateString(), '31 Jul + 14 + 7 — never 31 Jul + 7');
        $this->assertSame('2027-08-21', $contract->end_date->toDateString());
        $this->assertSame('2027-07-31', $contract->original_end_date->toDateString(), 'set once, by the FIRST approved addendum');
    }

    /** Pengurangan waktu is real: negative days pull the deadline earlier. */
    public function test_a_time_reduction_pulls_the_deadline_earlier(): void
    {
        $contract = $this->timedContract();

        $this->approveOrder($this->timeAddendum($contract, -14));

        $this->assertSame('2027-07-17', $contract->refresh()->end_date->toDateString());
    }

    public function test_a_reduction_cannot_shift_the_end_before_the_start(): void
    {
        $contract = $this->timedContract();
        $order = $this->timeAddendum($contract, -400);
        $order->submit($this->financeUser());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tanggal mulai/');

        $this->service->approve($order->refresh(), $this->financeApprover());
    }

    public function test_a_rejected_addendum_shifts_nothing(): void
    {
        $contract = $this->timedContract();
        $project = $this->makeProject([
            'contract_id' => $contract->id,
            'end_date' => '2027-07-31',
        ]);

        $order = $this->timeAddendum($contract, 14);
        $order->submit($this->financeUser());
        $this->service->reject($order->refresh(), $this->financeApprover(), 'Belum disepakati pelanggan');

        $contract->refresh();

        $this->assertSame('2027-07-31', $contract->end_date->toDateString());
        $this->assertNull($contract->original_end_date);
        $this->assertNull($order->refresh()->new_end_date);
        $this->assertSame('2027-07-31', $project->refresh()->end_date->toDateString());
    }

    // ------------------------------------------------------------------ gate

    public function test_a_project_in_warranty_refuses_a_time_addendum_by_name(): void
    {
        $contract = $this->timedContract();
        $this->makeProject([
            'contract_id' => $contract->id,
            'status' => 'warranty',
            'end_date' => '2027-07-31',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Masa Pemeliharaan/');

        $this->timeAddendum($contract, 14);
    }

    /**
     * The gate holds at APPROVAL too — the status can change while the addendum
     * sits submitted, and the locked re-read inside the transaction is what
     * decides, not the screen that created the draft.
     */
    public function test_a_project_closed_after_submission_still_refuses_at_approval(): void
    {
        $contract = $this->timedContract();
        $project = $this->makeProject([
            'contract_id' => $contract->id,
            'end_date' => '2027-07-31',
        ]);

        $order = $this->timeAddendum($contract, 14);
        $order->submit($this->financeUser());

        $project->forceFill(['status' => 'closed'])->save();

        try {
            $this->service->approve($order->refresh(), $this->financeApprover());
            $this->fail('a closed project must refuse the shift');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Ditutup', $e->getMessage());
        }

        $this->assertSame('2027-07-31', $contract->refresh()->end_date->toDateString(), 'the refused approval rolled back');
        $this->assertSame('2027-07-31', $project->refresh()->end_date->toDateString());
    }

    // ------------------------------------------------------------- billing

    public function test_a_time_addendum_has_no_value_to_schedule(): void
    {
        $contract = $this->timedContract();
        $order = $this->approveOrder($this->timeAddendum($contract, 14));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak membawa nilai/');

        $this->service->scheduleTermin($order->refresh(), ['due_date' => '2027-08-01']);
    }

    // ------------------------------------------------------------- printing

    /**
     * The F/BATK time lines mirror the money-line decision: an approved
     * addendum quotes the record, an unapproved one says plainly it has not
     * been agreed — and never prints a projected date.
     */
    public function test_the_printed_time_lines_quote_only_the_record(): void
    {
        $forms = app(CrmFormService::class);
        $contract = $this->timedContract();
        $order = $this->timeAddendum($contract, 14);

        $draft = $forms->changeOrderTimeValues($order);

        $this->assertSame('31 Juli 2027', $draft[0]['keterangan'], 'the signed end date');
        $this->assertSame('+14 hari', $draft[1]['keterangan']);
        $this->assertStringContainsString('belum disetujui', $draft[2]['uraian']);
        $this->assertSame('31 Juli 2027', $draft[2]['keterangan'], 'the CURRENT date — never end_date + days on an unapproved sheet');

        $this->approveOrder($order);

        $approved = $forms->changeOrderTimeValues($order->refresh());

        $this->assertSame('31 Juli 2027', $approved[0]['keterangan'], 'original_end_date keeps the signed date on later prints');
        $this->assertStringContainsString('setelah perubahan ini disetujui', $approved[2]['uraian']);
        $this->assertSame('14 Agustus 2027', $approved[2]['keterangan']);
    }

    /** What the kop's PERPANJANGAN WAKTU I/II lines are composed from. */
    public function test_the_kop_reads_approved_extensions_in_date_order(): void
    {
        $forms = app(CrmFormService::class);
        $contract = $this->timedContract();

        $first = $this->approveOrder($this->timeAddendum($contract, 14, ['change_date' => '2027-05-01']));
        $this->approveOrder($this->timeAddendum($contract->refresh(), 7, ['change_date' => '2027-06-01']));

        // A rejected one never reaches the kop.
        $rejected = $this->timeAddendum($contract->refresh(), 30, ['change_date' => '2027-04-01']);
        $rejected->submit($this->financeUser());
        $this->service->reject($rejected->refresh(), $this->financeApprover());

        $extensions = $forms->approvedTimeExtensions($contract->refresh());

        $this->assertSame([14, 7], $extensions->pluck('days_change')->all());
        $this->assertSame($first->code, $extensions->first()->code);
        $this->assertSame(['2027-08-14', '2027-08-21'], $extensions->map(
            fn (ContractChangeOrder $row): string => $row->new_end_date->toDateString(),
        )->all());
    }
}
