<?php

namespace Tests\Feature\Crm;

use LogicException;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Services\ContractChangeOrderService;
use Modules\Crm\Services\TerminBillingService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Temuan #14 — CCO → jadwal termin.
 *
 * ContractChangeOrderService has promised since its first docblock that 'added
 * scope is billed through new termins', and the path never existed: an
 * approved contract's schedule is frozen, so the value an approved CCO added
 * could only be billed by a manual invoice — no due_date, no billed_at, no row
 * in the antrean siap tagih. Precisely the 'pendapatan tercecer' the schedule
 * exists to prevent, on precisely the money (added scope) most easily
 * forgotten.
 *
 * scheduleTermin() is the follow-up wizard behind an approved CCO: ONE new
 * termin worth value_change, with a due date so the queue can chase it. The
 * signed schedule is never touched — percent stays the signed story (summing
 * 100 of the signed value), the CCO termin is amount-based (percent 0),
 * because ArInvoiceService bills from the amount column whenever it is set.
 */
class ChangeOrderTerminScheduleTest extends ErpTestCase
{
    use FinanceFixtures;

    private ContractChangeOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->service = app(ContractChangeOrderService::class);
    }

    // -------------------------------------------------------------- fixtures

    /** An approved contract with its signed 100% schedule: DP 20% + pelunasan 80%. */
    private function contractWithSchedule(float $value = 1_000_000_000): Contract
    {
        $contract = $this->makeContract($this->makeCustomer(), ['value' => $value]);
        $this->makeTermin($contract, 1, 'DP 20%', 20, round($value * 0.20, 2));
        $this->makeTermin($contract, 2, 'Pelunasan 80%', 80, round($value * 0.80, 2));

        return $contract;
    }

    private function order(Contract $contract, float $change = 150_000_000): ContractChangeOrder
    {
        return $this->service->create([
            'contract_id' => $contract->id,
            'change_date' => '2026-06-01',
            'title' => 'Tambah pekerjaan ME lantai 9',
            'value_change' => $change,
            'reason' => 'permintaan_pelanggan',
        ]);
    }

    private function approvedOrder(Contract $contract, float $change = 150_000_000): ContractChangeOrder
    {
        $order = $this->order($contract, $change);
        $order->submit($this->financeUser());

        return $this->service->approve($order->refresh(), $this->financeApprover());
    }

    // ------------------------------------------------------------ happy path

    public function test_an_approved_addition_schedules_a_new_termin_worth_the_value_change(): void
    {
        $contract = $this->contractWithSchedule();
        $order = $this->approvedOrder($contract, 150_000_000);

        $termin = $this->service->scheduleTermin($order, ['due_date' => '2026-08-20']);

        $this->assertEqualsWithDelta(150_000_000, (float) $termin->amount, 0.01);
        $this->assertSame(3, (int) $termin->termin_no, 'appended after the signed schedule');
        $this->assertEqualsWithDelta(0.0, (float) $termin->percent, 0.0001, 'amount-based, the signed percents stay a 100% story');
        $this->assertSame('2026-08-20', $termin->due_date->toDateString());
        $this->assertNull($termin->billed_at);
        $this->assertFalse($termin->is_retention);
        $this->assertStringContainsString($order->code, $termin->name);
        $this->assertSame($termin->id, (int) $order->refresh()->termin_id, 'the CCO remembers its termin');
    }

    public function test_a_custom_name_wins_over_the_default(): void
    {
        $order = $this->approvedOrder($this->contractWithSchedule());

        $termin = $this->service->scheduleTermin($order, [
            'due_date' => '2026-08-20',
            'name' => 'Termin CCO — ME lantai 9',
        ]);

        $this->assertSame('Termin CCO — ME lantai 9', $termin->name);
    }

    /**
     * The whole point of scheduling instead of invoicing by hand: the added
     * value now has a due date, so once that date passes the antrean siap
     * tagih reports it — with how long it has been waiting — instead of the
     * money silently depending on somebody's memory.
     */
    public function test_the_new_termin_enters_the_billing_ready_queue(): void
    {
        $contract = $this->contractWithSchedule();
        $order = $this->approvedOrder($contract, 150_000_000);

        $termin = $this->service->scheduleTermin($order, ['due_date' => '2026-06-30']);

        $rows = app(TerminBillingService::class)->billingReady('2026-07-31', $contract->id);
        $row = collect($rows)->firstWhere('termin_id', $termin->id);

        $this->assertNotNull($row, 'the scheduled added value must be chased like any other termin');
        $this->assertSame('jadwal', $row['reason']);
        $this->assertSame(31, $row['days_waiting']);
        $this->assertEqualsWithDelta(150_000_000, $row['amount'], 0.01);
    }

    /** The CCO termin bills through the one ordinary termin-invoice path. */
    public function test_the_new_termin_bills_through_the_ordinary_invoice_path(): void
    {
        $order = $this->approvedOrder($this->contractWithSchedule(), 150_000_000);
        $termin = $this->service->scheduleTermin($order, ['due_date' => '2026-08-20']);

        $invoice = $this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-08-21',
        ]);

        $this->assertEqualsWithDelta(150_000_000, (float) $invoice->dpp, 0.01, 'DPP comes from the termin amount');
    }

    /** Existing termins are never touched — that is the CCO contract with history. */
    public function test_the_signed_schedule_keeps_its_percents_and_amounts(): void
    {
        $contract = $this->contractWithSchedule();
        $order = $this->approvedOrder($contract, 150_000_000);

        $before = $contract->termins()->orderBy('id')->get()
            ->map(fn (ContractTermin $t): array => [$t->id, (float) $t->percent, (float) $t->amount])->all();

        $this->service->scheduleTermin($order, ['due_date' => '2026-08-20']);

        $after = $contract->refresh()->termins()->orderBy('id')->take(2)->get()
            ->map(fn (ContractTermin $t): array => [$t->id, (float) $t->percent, (float) $t->amount])->all();

        $this->assertSame($before, $after);
    }

    /**
     * The invariant the wizard restores: after a CCO the schedule's AMOUNTS
     * cover the contract's current value again — signed termins cover the
     * signed value, each scheduled CCO adds exactly its value_change.
     */
    public function test_termin_amounts_cover_the_whole_contract_value_again(): void
    {
        $contract = $this->contractWithSchedule(1_000_000_000);
        $order = $this->approvedOrder($contract, 150_000_000);

        $this->service->scheduleTermin($order, ['due_date' => '2026-08-20']);
        $contract->refresh();

        // 200.000.000 + 800.000.000 + 150.000.000 = 1.150.000.000 = nilai kini.
        $this->assertEqualsWithDelta(
            (float) $contract->value,
            (float) $contract->termins()->sum('amount'),
            0.01,
        );
    }

    // ---------------------------------------------------------------- guards

    /** Pekerjaan kurang reduces what remains billable; it adds nothing to bill. */
    public function test_a_reduction_cannot_be_scheduled(): void
    {
        $contract = $this->contractWithSchedule();
        $order = $this->approvedOrder($contract, -80_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/pekerjaan kurang/');

        try {
            $this->service->scheduleTermin($order, ['due_date' => '2026-08-20']);
        } finally {
            $this->assertSame(2, $contract->termins()->count());
        }
    }

    /** A draft CCO has not moved the contract value; there is nothing to bill yet. */
    public function test_a_change_order_that_is_not_approved_cannot_schedule(): void
    {
        $contract = $this->contractWithSchedule();
        $order = $this->order($contract, 150_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah disetujui/');

        try {
            $this->service->scheduleTermin($order, ['due_date' => '2026-08-20']);
        } finally {
            $this->assertSame(2, $contract->termins()->count());
        }
    }

    /**
     * Idempotent via the re-read: the stamp is checked on a fresh row inside
     * the transaction, so the double-clicked wizard schedules once and the
     * second click is told so — not two termins both worth Rp 150 juta.
     */
    public function test_scheduling_twice_is_refused_and_leaves_one_termin(): void
    {
        $contract = $this->contractWithSchedule();
        $order = $this->approvedOrder($contract, 150_000_000);

        $this->service->scheduleTermin($order, ['due_date' => '2026-08-20']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah dijadwalkan/');

        try {
            $this->service->scheduleTermin($order, ['due_date' => '2026-09-20']);
        } finally {
            $this->assertSame(3, $contract->termins()->count());
        }
    }

    /**
     * A fully billed schedule is a finished billing story — its closing termin
     * (BAST/retensi) has been invoiced and everything downstream (retention
     * release, warranty) anchors to it. A termin appended AFTER the closing
     * one would reopen that story; scope agreed at that point is billed as a
     * manual invoice on the same contract instead.
     */
    public function test_a_fully_billed_schedule_refuses_a_new_termin(): void
    {
        $contract = $this->contractWithSchedule();
        $contract->termins()->update(['billed_at' => '2026-05-31']);
        $order = $this->approvedOrder($contract, 150_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/invoice manual/');

        try {
            $this->service->scheduleTermin($order, ['due_date' => '2026-08-20']);
        } finally {
            $this->assertSame(2, $contract->termins()->count());
        }
    }

    // -------------------------------------------------------------- endpoint

    public function test_the_endpoint_schedules_and_refuses_a_second_attempt(): void
    {
        $contract = $this->contractWithSchedule();
        $order = $this->approvedOrder($contract, 150_000_000);
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson("/api/crm/contract-change-orders/{$order->id}/schedule-termin", ['due_date' => '2026-08-20'])
            ->assertCreated()
            ->assertJsonPath('data.termin_no', 3);

        $this->actingAs($admin)
            ->postJson("/api/crm/contract-change-orders/{$order->id}/schedule-termin", ['due_date' => '2026-09-20'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'sudah dijadwalkan'));

        $this->assertSame(3, $contract->termins()->count());
    }

    /** Without a due date the termin can never surface in the queue — refused up front. */
    public function test_the_endpoint_requires_a_due_date(): void
    {
        $order = $this->approvedOrder($this->contractWithSchedule());

        $this->actingAs($this->adminUser())
            ->postJson("/api/crm/contract-change-orders/{$order->id}/schedule-termin", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_date');
    }

    /** Ekskalasi harga adds billable value exactly like added scope does. */
    public function test_a_price_escalation_can_schedule_its_termin_too(): void
    {
        $contract = $this->contractWithSchedule();
        $order = $this->service->create([
            'contract_id' => $contract->id,
            'change_date' => '2026-06-01',
            'title' => 'Eskalasi harga tahun ke-2 (indeks BPS)',
            'change_type' => 'eskalasi_harga',
            'value_change' => 45_000_000,
        ]);
        $order->submit($this->financeUser());
        $order = $this->service->approve($order->refresh(), $this->financeApprover());

        $termin = $this->service->scheduleTermin($order, ['due_date' => '2026-08-20']);

        $this->assertEqualsWithDelta(45_000_000, (float) $termin->amount, 0.01);
    }
}
