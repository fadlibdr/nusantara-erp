<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Services\MeasurementService;
use Tests\ErpTestCase;

/**
 * P3 — THE ceiling: a measured volume can never exceed what the contract and
 * its approved addenda actually bought.
 *
 * This is the one rule an owner opname exists to enforce. Without it the sheet
 * the MK signs is a wish: 1.400 m3 of galian claimed against 1.000 m3 of
 * contract, billed, paid, and only found at the final account — where the money
 * has already left. The refusal therefore names the ITEM and BOTH NUMBERS, so
 * the QS can see at a glance whether the fix is the opname (mistyped volume) or
 * the register (a CCO whose volume nobody recorded).
 */
class ProgressMeasurementCeilingTest extends ErpTestCase
{
    use OpnameFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOpnameWorld();
    }

    private function service(): MeasurementService
    {
        return app(MeasurementService::class);
    }

    /** @return array<string, mixed> */
    private function payload(array $items, string $start = '2026-06-01', string $end = '2026-06-30'): array
    {
        return [
            'project_id' => $this->project->id,
            'period_start' => $start,
            'period_end' => $end,
            'items' => $items,
        ];
    }

    public function test_volume_within_the_contract_quantity_is_measured(): void
    {
        $opname = $this->service()->create($this->payload([$this->line('A.1', 400)]));

        $this->assertSame(DocumentStatus::Draft, $opname->status);
        $this->assertStringStartsWith('OPN/', $opname->code);

        $line = $opname->items()->first();
        $this->assertSame('0.000', $line->qty_prev);
        $this->assertSame('400.000', $line->qty_this);
        $this->assertSame('400.000', $line->qty_cum);
        // 400 m3 x Rp 200.000
        $this->assertSame('80000000.00', $line->amount);
        $this->assertSame('80000000.00', $opname->refresh()->period_amount);
    }

    public function test_volume_exactly_at_the_contract_quantity_is_measured(): void
    {
        $opname = $this->service()->create($this->payload([$this->line('A.1', 1000)]));

        $this->assertSame('1000.000', $opname->items()->first()->qty_cum);
    }

    public function test_volume_above_the_contract_quantity_is_refused_naming_the_item_and_both_numbers(): void
    {
        try {
            $this->service()->create($this->payload([$this->line('A.1', 1400)]));
            $this->fail('Opname melampaui volume kontrak seharusnya ditolak.');
        } catch (ValidationException $e) {
            $message = implode(' ', array_merge(...array_values($e->errors())));

            $this->assertStringContainsString('Galian tanah biasa', $message);
            $this->assertStringContainsString('1.400,000', $message);  // what was measured
            $this->assertStringContainsString('1.000,000', $message);  // what the contract bought
        }

        $this->assertSame(0, ProgressMeasurement::query()->count());
    }

    public function test_an_approved_cco_volume_raises_the_ceiling(): void
    {
        $this->makeVariation('A.1', 500); // approved CCO: +500 m3 -> ceiling 1.500

        $opname = $this->service()->create($this->payload([$this->line('A.1', 1400)]));

        $this->assertSame('1400.000', $opname->items()->first()->qty_cum);
    }

    public function test_a_cco_volume_that_is_not_yet_approved_does_not_raise_the_ceiling(): void
    {
        $this->makeVariation('A.1', 500, DocumentStatus::Submitted);

        $this->expectException(ValidationException::class);

        $this->service()->create($this->payload([$this->line('A.1', 1400)]));
    }

    public function test_a_pekerjaan_kurang_volume_lowers_the_ceiling(): void
    {
        $this->makeVariation('A.1', -300); // approved CCO: -300 m3 -> ceiling 700

        try {
            $this->service()->create($this->payload([$this->line('A.1', 800)]));
            $this->fail('Opname melampaui volume kontrak setelah pekerjaan kurang seharusnya ditolak.');
        } catch (ValidationException $e) {
            $message = implode(' ', array_merge(...array_values($e->errors())));
            $this->assertStringContainsString('700,000', $message);
        }
    }

    public function test_qty_prev_rolls_forward_from_the_last_approved_opname_and_the_ceiling_is_cumulative(): void
    {
        $first = $this->service()->create($this->payload([$this->line('A.1', 600)]));
        $first->submit($this->makerUser());
        $this->service()->approve($first, $this->checkerUser());

        $second = $this->service()->create(
            $this->payload([$this->line('A.1', 200)], '2026-07-01', '2026-07-31')
        );

        $line = $second->items()->first();
        $this->assertSame('600.000', $line->qty_prev);
        $this->assertSame('800.000', $line->qty_cum);

        // 600 already approved + 500 more would be 1.100 against a 1.000 ceiling.
        $this->expectException(ValidationException::class);
        $this->service()->update($second, ['items' => [$this->line('A.1', 500)]]);
    }

    public function test_an_unapproved_opname_does_not_move_qty_prev(): void
    {
        $first = $this->service()->create($this->payload([$this->line('A.1', 600)]));
        $first->submit($this->makerUser()); // submitted, NOT approved

        $second = $this->service()->create(
            $this->payload([$this->line('A.1', 200)], '2026-07-01', '2026-07-31')
        );

        $this->assertSame('0.000', $second->items()->first()->qty_prev);
    }

    public function test_an_item_outside_the_contract_boq_is_refused(): void
    {
        $other = Boq::query()->create([
            'title' => 'RAB proyek lain',
            'status' => DocumentStatus::Approved,
        ]);
        $section = $other->sections()->create(['section_no' => 'A', 'name' => 'Lain']);
        $alien = BoqItem::query()->create([
            'boq_id' => $other->id,
            'section_id' => $section->id,
            'wbs_code' => 'Z.9',
            'description' => 'Pekerjaan proyek lain',
            'qty' => 10,
            'unit' => 'ls',
            'unit_price' => 1000,
            'amount' => 10000,
        ]);

        $this->expectException(ValidationException::class);

        $this->service()->create($this->payload([
            ['boq_item_id' => $alien->id, 'location_id' => null, 'qty_this' => 1],
        ]));
    }

    private function makerUser(): User
    {
        return User::query()->create([
            'name' => 'Pengukur', 'email' => 'qs@test.local', 'password' => 'password', 'is_active' => true,
        ]);
    }

    private function checkerUser(): User
    {
        return User::query()->create([
            'name' => 'Manajer Proyek', 'email' => 'pm@test.local', 'password' => 'password', 'is_active' => true,
        ]);
    }
}
