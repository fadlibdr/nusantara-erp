<?php

namespace Tests\Feature\Crm;

use Illuminate\Support\Carbon;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Modules\Crm\Services\TerminBillingService;
use Modules\Projects\Models\Milestone;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * Antrean siap tagih — "what may we invoice today, and since when".
 *
 * Two live contracts describe the two halves of this queue, and both of them
 * lost money in the same silence.
 *
 * CTR/2026/I/0001: milestone "Progres fisik 50%" achieved 27-03-2026 releases a
 * termin of Rp 14.550.000.000. Still unbilled on 31-07-2026 — 126 days.
 *
 * CTR/2026/III/0003, pemeliharaan CCTV: Rp 120 juta per triwulan. Triwulan I was
 * invoiced on 06-04-2026, Triwulan II never was. There was no milestone to miss
 * and no date to be late against — a calendar termin comes due because a quarter
 * ended, and until due_date existed the schema could not express that at all.
 *
 * The clock in every expectation below is pinned to 31-07-2026, the day the gap
 * was found, so the day counts are the real ones.
 */
class BillingReadyTest extends ErpTestCase
{
    private const TODAY = '2026-07-31';

    private TerminBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY.' 09:00:00');
        $this->billing = app(TerminBillingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------- fixtures

    private function contract(string $title, float $value, DocumentStatus $status = DocumentStatus::Approved): Contract
    {
        $customer = Customer::query()->create([
            'name' => 'Pelanggan '.str()->random(4),
            'is_pkp' => true,
            'status' => 'active',
        ]);

        return Contract::query()->create([
            'customer_id' => $customer->id,
            'title' => $title,
            'scope_type' => 'construction',
            'value' => $value,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'status' => $status,
        ]);
    }

    private function termin(Contract $contract, int $no, string $name, float $amount, array $attributes = []): ContractTermin
    {
        return ContractTermin::query()->create(array_merge([
            'contract_id' => $contract->id,
            'termin_no' => $no,
            'name' => $name,
            'percent' => round($amount / (float) $contract->value * 100, 4),
            'amount' => $amount,
        ], $attributes));
    }

    private function achieveMilestone(Contract $contract, ContractTermin $termin, string $on, string $name = 'Progres fisik 50%'): Milestone
    {
        $project = Project::query()->create([
            'name' => 'Proyek '.$contract->code,
            'contract_id' => $contract->id,
            'customer_id' => $contract->customer_id,
            'type' => 'construction',
            'status' => 'active',
        ]);

        return Milestone::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'due_date' => $on,
            'achieved_date' => $on,
            'termin_id' => $termin->id,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function queue(): array
    {
        return $this->billing->billingReady(self::TODAY);
    }

    private function rowFor(ContractTermin $termin): ?array
    {
        foreach ($this->queue() as $row) {
            if ($row['termin_id'] === $termin->id) {
                return $row;
            }
        }

        return null;
    }

    // ------------------------------------------------------ the milestone half

    /**
     * THE Rp 14,55 MILIAR. Achieved 27-03-2026, unbilled on 31-07-2026: March has
     * 4 days left, then 30 + 31 + 30 + 31 for April–July = 126 days waiting.
     */
    public function test_a_termin_released_by_an_achieved_milestone_is_in_the_queue(): void
    {
        $contract = $this->contract('Pembangunan Gedung Kantor Graha Sentosa', 48_500_000_000);
        $termin = $this->termin($contract, 2, 'Progress 50%', 14_550_000_000);
        $this->achieveMilestone($contract, $termin, '2026-03-27', 'Progres fisik 50% — syarat penagihan Termin 2');

        $row = $this->rowFor($termin);

        $this->assertNotNull($row, 'the milestone was achieved four months ago');
        $this->assertSame('milestone', $row['reason']);
        $this->assertSame('2026-03-27', $row['trigger_date']);
        $this->assertSame(126, $row['days_waiting']);
        $this->assertEqualsWithDelta(14_550_000_000, $row['amount'], 0.01);
        $this->assertSame($contract->code, $row['contract_code']);
        $this->assertSame(2, $row['termin_no']);
        $this->assertSame('Progress 50%', $row['termin_name']);
        $this->assertStringContainsString('Progres fisik 50%', $row['milestone_name']);
    }

    /** A milestone still pending releases nothing — the work is not certified. */
    public function test_an_unachieved_milestone_keeps_its_termin_out(): void
    {
        $contract = $this->contract('Pembangunan Gedung Kantor Graha Sentosa', 48_500_000_000);
        $termin = $this->termin($contract, 3, 'Progress 80%', 14_550_000_000);

        $project = Project::query()->create([
            'name' => 'Proyek '.$contract->code,
            'contract_id' => $contract->id,
            'type' => 'construction',
            'status' => 'active',
        ]);
        Milestone::query()->create([
            'project_id' => $project->id,
            'name' => 'Progres fisik 80% — syarat penagihan Termin 3',
            'due_date' => '2026-10-31',
            'termin_id' => $termin->id,
        ]);

        $this->assertNull($this->rowFor($termin));
    }

    // ------------------------------------------------------- the calendar half

    /**
     * THE MISSING QUARTER. Triwulan II fell due 30-06-2026 and nothing invoiced
     * it: 31-07 minus 30-06 is 31 days. Before due_date existed this row could
     * not be produced by any query, because nothing recorded that a quarter had
     * come due.
     */
    public function test_a_calendar_termin_past_its_due_date_is_in_the_queue(): void
    {
        $contract = $this->contract('Pemeliharaan CCTV & Akses Kontrol RS Medika Husada', 480_000_000);
        $this->termin($contract, 1, 'Triwulan I 25%', 120_000_000, ['due_date' => '2026-03-31', 'billed_at' => '2026-04-06']);
        $termin = $this->termin($contract, 2, 'Triwulan II 25%', 120_000_000, ['due_date' => '2026-06-30']);

        $row = $this->rowFor($termin);

        $this->assertNotNull($row, 'a quarter ended and nobody billed it');
        $this->assertSame('jadwal', $row['reason']);
        $this->assertSame('2026-06-30', $row['trigger_date']);
        $this->assertSame(31, $row['days_waiting']);
        $this->assertEqualsWithDelta(120_000_000, $row['amount'], 0.01);
        $this->assertNull($row['milestone_name']);
    }

    /**
     * OFF BY ONE, IN THE DIRECTION THAT COSTS MONEY. A termin due today is
     * billable today; excluding it delays every invoice by a day and, on a
     * quarter boundary, into the next month's revenue.
     */
    public function test_a_termin_falling_due_today_is_already_billable(): void
    {
        $contract = $this->contract('Pemeliharaan CCTV & Akses Kontrol RS Medika Husada', 480_000_000);
        $termin = $this->termin($contract, 3, 'Triwulan III 25%', 120_000_000, ['due_date' => self::TODAY]);

        $row = $this->rowFor($termin);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['days_waiting']);
    }

    /** Tomorrow's termin is not owed yet, and a queue that says it is is noise. */
    public function test_a_termin_not_yet_due_stays_out(): void
    {
        $contract = $this->contract('Pemeliharaan CCTV & Akses Kontrol RS Medika Husada', 480_000_000);
        $termin = $this->termin($contract, 4, 'Triwulan IV 25%', 120_000_000, ['due_date' => '2026-09-30']);

        $this->assertNull($this->rowFor($termin));
    }

    // ------------------------------------------------------------- exclusions

    /** The queue is work to do. An invoiced termin is work already done. */
    public function test_a_billed_termin_leaves_the_queue(): void
    {
        $contract = $this->contract('Pembangunan Gedung Kantor Graha Sentosa', 48_500_000_000);
        $milestoneTermin = $this->termin($contract, 2, 'Progress 50%', 14_550_000_000, ['billed_at' => '2026-04-02']);
        $calendarTermin = $this->termin($contract, 3, 'Progress 80%', 14_550_000_000, [
            'due_date' => '2026-05-31',
            'billed_at' => '2026-06-05',
        ]);
        $this->achieveMilestone($contract, $milestoneTermin, '2026-03-27');

        $this->assertNull($this->rowFor($milestoneTermin));
        $this->assertNull($this->rowFor($calendarTermin));
        $this->assertSame([], $this->queue());
    }

    /**
     * A draft contract's schedule is still being negotiated — its amounts are a
     * proposal, and invoicing against them would bill a number the customer has
     * not agreed to.
     */
    public function test_only_an_approved_contract_can_produce_billable_termins(): void
    {
        $draft = $this->contract('Penawaran belum diteken', 1_000_000_000, DocumentStatus::Draft);
        $terminOfDraft = $this->termin($draft, 1, 'DP 20%', 200_000_000, ['due_date' => '2026-05-01']);

        $cancelled = $this->contract('Kontrak dibatalkan', 1_000_000_000, DocumentStatus::Cancelled);
        $terminOfCancelled = $this->termin($cancelled, 1, 'DP 20%', 200_000_000, ['due_date' => '2026-05-01']);

        $this->assertNull($this->rowFor($terminOfDraft));
        $this->assertNull($this->rowFor($terminOfCancelled));
    }

    // -------------------------------------------------------------- the order

    /**
     * The top of the list is the point of the list: the money that has been
     * waiting longest, whoever it belongs to.
     */
    public function test_the_longest_wait_sits_at_the_top(): void
    {
        $building = $this->contract('Pembangunan Gedung Kantor Graha Sentosa', 48_500_000_000);
        $oldest = $this->termin($building, 2, 'Progress 50%', 14_550_000_000);
        $this->achieveMilestone($building, $oldest, '2026-03-27');

        $maintenance = $this->contract('Pemeliharaan CCTV & Akses Kontrol', 480_000_000);
        $middle = $this->termin($maintenance, 2, 'Triwulan II 25%', 120_000_000, ['due_date' => '2026-06-30']);
        $newest = $this->termin($maintenance, 3, 'Triwulan III 25%', 120_000_000, ['due_date' => '2026-07-25']);

        $queue = $this->queue();

        $this->assertSame(
            [$oldest->id, $middle->id, $newest->id],
            array_column($queue, 'termin_id'),
        );
        $this->assertSame([126, 31, 6], array_column($queue, 'days_waiting'));
    }

    /**
     * A termin can be released twice over — the milestone was certified late, or
     * the calendar date was set as a backstop. The clock has to start at the
     * FIRST of the two, or the oldest debt quietly reports itself as fresh.
     */
    public function test_when_both_triggers_apply_the_earlier_one_is_the_trigger(): void
    {
        $contract = $this->contract('Pembangunan Gedung Kantor Graha Sentosa', 48_500_000_000);
        $termin = $this->termin($contract, 2, 'Progress 50%', 14_550_000_000, ['due_date' => '2026-06-30']);
        $this->achieveMilestone($contract, $termin, '2026-03-27');

        $row = $this->rowFor($termin);

        $this->assertSame('milestone', $row['reason']);
        $this->assertSame('2026-03-27', $row['trigger_date'], 'the milestone came first');
        $this->assertSame(126, $row['days_waiting']);
    }

    // ------------------------------------------------------------- the endpoint

    /** The queue is a finance work list, so fin.view is what opens it. */
    public function test_the_endpoint_returns_the_queue_with_its_headline_total(): void
    {
        $building = $this->contract('Pembangunan Gedung Kantor Graha Sentosa', 48_500_000_000);
        $milestoneTermin = $this->termin($building, 2, 'Progress 50%', 14_550_000_000);
        $this->achieveMilestone($building, $milestoneTermin, '2026-03-27');

        $maintenance = $this->contract('Pemeliharaan CCTV & Akses Kontrol', 480_000_000);
        $this->termin($maintenance, 2, 'Triwulan II 25%', 120_000_000, ['due_date' => '2026-06-30']);

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/crm/contract-termins/billing-ready?as_of='.self::TODAY)
            ->assertOk();

        $this->assertSame(2, $response->json('meta.count'));
        // Rp 14.550.000.000 + Rp 120.000.000 = Rp 14.670.000.000.
        $this->assertEqualsWithDelta(14_670_000_000, $response->json('meta.total_amount'), 0.01);
        $this->assertSame('milestone', $response->json('data.0.reason'));
        $this->assertSame($building->id, $response->json('data.0.contract_id'));
        $this->assertSame("#/d/crm/contracts/{$building->id}", $response->json('data.0.link'));
        $this->assertSame('jadwal', $response->json('data.1.reason'));
    }

    /** Every row carries who to bill — a queue you cannot act on is a report. */
    public function test_every_row_names_the_customer(): void
    {
        $contract = $this->contract('Pemeliharaan CCTV & Akses Kontrol', 480_000_000);
        $contract->customer->forceFill(['name' => 'RS Medika Husada'])->save();
        $termin = $this->termin($contract, 2, 'Triwulan II 25%', 120_000_000, ['due_date' => '2026-06-30']);

        $this->assertSame('RS Medika Husada', $this->rowFor($termin)['customer_name']);
    }

    // --------------------------------------------------- setting the due date

    /**
     * The maintenance contract is why the single-termin endpoint exists: its
     * first quarter is billed, so ContractService will never again accept a
     * replacement schedule for it — and without this route the remaining
     * quarters could never be given a due date at all.
     */
    public function test_a_due_date_can_be_set_on_a_contract_that_already_has_billed_termins(): void
    {
        $contract = $this->contract('Pemeliharaan CCTV & Akses Kontrol', 480_000_000);
        $this->termin($contract, 1, 'Triwulan I 25%', 120_000_000, ['billed_at' => '2026-04-06']);
        $termin = $this->termin($contract, 2, 'Triwulan II 25%', 120_000_000);

        $this->actingAs($this->adminUser())
            ->putJson("/api/crm/contract-termins/{$termin->id}", ['due_date' => '2026-06-30'])
            ->assertOk()
            ->assertJsonPath('data.due_date', '2026-06-30');

        $this->assertSame(31, $this->rowFor($termin)['days_waiting']);
    }

    /** Rescheduling something already invoiced rewrites history for no one. */
    public function test_a_billed_termin_cannot_be_rescheduled(): void
    {
        $contract = $this->contract('Pemeliharaan CCTV & Akses Kontrol', 480_000_000);
        $termin = $this->termin($contract, 1, 'Triwulan I 25%', 120_000_000, ['billed_at' => '2026-04-06']);

        $this->actingAs($this->adminUser())
            ->putJson("/api/crm/contract-termins/{$termin->id}", ['due_date' => '2026-06-30'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'sudah ditagihkan'));

        $this->assertNull($termin->refresh()->due_date);
    }
}
