<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Estimation\Services\BoqService;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Services\MeasurementService;
use Tests\ErpTestCase;

/**
 * P3 REPAIR — THE MEASUREMENT HISTORY MUST FOLLOW THE ITEM ACROSS A BOQ REVISION.
 *
 * BoqService::copyVersion clones a BOQ at version + 1 and gives every line a
 * BRAND NEW row id. An opname keyed its history on that row id, so the day the
 * revised BOQ was approved the whole approved history became unreachable:
 * qty_prev fell back to 0,000 and the ceiling reset to the fresh line's qty.
 * The same 1.000 m3 of galian could then be measured, approved and BILLED A
 * SECOND TIME, against a contract that bought it once.
 *
 * What survives a revision is the item's NUMBER (wbs_code) — copyVersion carries
 * it, and so does the "buat Versi Baru lalu impor" route BoqService's own
 * dependency blockers send an estimator down. That is the identity the ceiling
 * and qty_prev key on now, and these are the four facts that keep it honest.
 */
class ProgressMeasurementBoqRevisionTest extends ErpTestCase
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

    /** Measure, submit and approve one volume against the BOQ item of $wbsCode. */
    private function approveOpname(int $boqItemId, float $qty, string $start, string $end): ProgressMeasurement
    {
        $opname = $this->service()->create($this->payload(
            [['boq_item_id' => $boqItemId, 'location_id' => null, 'qty_this' => $qty]],
            $start,
            $end,
        ));

        $opname->submit($this->makerUser());

        return $this->service()->approve($opname, $this->checkerUser());
    }

    /**
     * Revise the contract BOQ the way the application does — copyVersion, then
     * approve the copy — optionally re-pricing one item's volume on the way.
     *
     * @param  array<string, float>  $newQty  wbs_code => revised qty
     */
    private function reviseBoq(array $newQty = []): Boq
    {
        $copy = app(BoqService::class)->copyVersion($this->boq);

        foreach ($newQty as $code => $qty) {
            $item = $this->revisedItem($copy, $code);
            $item->forceFill([
                'qty' => $qty,
                'amount' => round($qty * (float) $item->unit_price, 2),
            ])->save();
        }

        $copy->forceFill(['status' => DocumentStatus::Approved])->save();

        return $copy->refresh();
    }

    /** The line of $wbsCode inside a revised BOQ — a different row id, same number. */
    private function revisedItem(Boq $boq, string $wbsCode): BoqItem
    {
        /** @var BoqItem $item */
        $item = BoqItem::query()
            ->where('boq_id', $boq->id)
            ->where('wbs_code', $wbsCode)
            ->firstOrFail();

        return $item;
    }

    /**
     * THE DOUBLE-BILLING REPRO. 1.000 m3 measured and approved against BOQ v1;
     * the BOQ is revised and approved unchanged; the same 1.000 m3 is measured
     * again on the v2 line. The contract bought 1.000 m3 — the second sheet must
     * be refused, naming the item and both numbers.
     */
    public function test_a_boq_revision_does_not_let_the_same_volume_be_measured_twice(): void
    {
        $this->approveOpname($this->boqItems['A.1']->id, 1000, '2026-06-01', '2026-06-30');

        $revised = $this->reviseBoq();
        $newItemId = $this->revisedItem($revised, 'A.1')->id;

        // The revision really did hand the item a new identity row.
        $this->assertNotSame((int) $this->boqItems['A.1']->id, (int) $newItemId);

        try {
            $this->service()->create($this->payload(
                [['boq_item_id' => $newItemId, 'location_id' => null, 'qty_this' => 1000]],
                '2026-07-01',
                '2026-07-31',
            ));
            $this->fail('Volume yang sudah diukur dan disetujui sebelum revisi BOQ seharusnya tetap dihitung.');
        } catch (ValidationException $e) {
            $message = implode(' ', array_merge(...array_values($e->errors())));

            $this->assertStringContainsString('Galian tanah biasa', $message);
            $this->assertStringContainsString('2.000,000', $message); // what the second sheet would make cumulative
            $this->assertStringContainsString('1.000,000', $message); // what the contract bought
        }

        // Only the first, legitimate opname exists.
        $this->assertSame(1, ProgressMeasurement::query()->count());
    }

    /** qty_prev is the approved history of the ITEM, not of the row id. */
    public function test_qty_prev_carries_across_a_boq_revision(): void
    {
        $this->approveOpname($this->boqItems['A.1']->id, 600, '2026-06-01', '2026-06-30');

        $revised = $this->reviseBoq();

        $second = $this->service()->create($this->payload(
            [['boq_item_id' => $this->revisedItem($revised, 'A.1')->id, 'location_id' => null, 'qty_this' => 200]],
            '2026-07-01',
            '2026-07-31',
        ));

        $line = $second->items()->first();
        $this->assertSame('600.000', $line->qty_prev);
        $this->assertSame('200.000', $line->qty_this);
        $this->assertSame('800.000', $line->qty_cum);
    }

    /**
     * The ceiling is the CURRENT approved BOQ's volume for that item: a revision
     * that raises 1.000 m3 to 1.500 m3 buys 500 m3 more, and refuses the metre
     * after that.
     */
    public function test_a_revision_that_raises_the_quantity_raises_the_ceiling(): void
    {
        $this->approveOpname($this->boqItems['A.1']->id, 1000, '2026-06-01', '2026-06-30');

        $revised = $this->reviseBoq(['A.1' => 1500]);
        $newItemId = $this->revisedItem($revised, 'A.1')->id;

        $second = $this->service()->create($this->payload(
            [['boq_item_id' => $newItemId, 'location_id' => null, 'qty_this' => 400]],
            '2026-07-01',
            '2026-07-31',
        ));

        $line = $second->items()->first();
        $this->assertSame('1000.000', $line->qty_prev);
        $this->assertSame('1400.000', $line->qty_cum);

        // 1.000 approved + 600 would be 1.600 against the revised 1.500 ceiling.
        $this->expectException(ValidationException::class);
        $this->service()->update($second, ['items' => [
            ['boq_item_id' => $newItemId, 'location_id' => null, 'qty_this' => 600],
        ]]);
    }

    /**
     * A CCO volume recorded against the PRE-REVISION line still raises the
     * ceiling afterwards — the addendum was signed against the item, and the
     * register keys on the same identity the ceiling does.
     */
    public function test_an_approved_cco_volume_survives_a_boq_revision(): void
    {
        $this->makeVariation('A.1', 500); // approved CCO on the v1 row: +500 m3

        $revised = $this->reviseBoq();

        $opname = $this->service()->create($this->payload(
            [['boq_item_id' => $this->revisedItem($revised, 'A.1')->id, 'location_id' => null, 'qty_this' => 1400]],
            '2026-07-01',
            '2026-07-31',
        ));

        $this->assertSame('1400.000', $opname->items()->first()->qty_cum);
    }

    /**
     * The two identity edge cases the class docblock describes, pinned.
     *
     * identityOf() is the single hinge the whole ceiling rests on, and both of
     * these were documented in prose while nothing held them there. The point
     * is not that either arrangement is GOOD — a BOQ numbering two lines A.1 is
     * a sheet with a mistake on it, and BoqService::duplicateWbsCodes says so —
     * but that the reading this service takes of one is written down and cannot
     * drift silently.
     */
    public function test_duplicate_item_numbers_share_one_ceiling_and_one_history(): void
    {
        $twin = BoqItem::query()->create([
            'boq_id' => $this->boq->id,
            'section_id' => $this->boqItems['A.1']->section_id,
            'wbs_code' => 'A.1',
            'description' => 'Galian tanah biasa (lanjutan)',
            'qty' => 300,
            'unit' => 'm3',
            'unit_price' => 200_000,
            'amount' => 300 * 200_000,
        ]);

        // Both rows answer with the SUM of both quantities: 1.000 + 300.
        $service = $this->service();
        $this->assertSame(1300.0, $service->ceilingFor($this->contract, $this->boqItems['A.1'], $this->project));
        $this->assertSame(1300.0, $service->ceilingFor($this->contract, $twin, $this->project));

        // And the history is shared: 1.300 measured against the FIRST row leaves
        // nothing for the second, because both name the same work.
        $this->approveOpname($this->boqItems['A.1']->id, 1300, '2026-06-01', '2026-06-30');

        try {
            $service->create($this->payload(
                [['boq_item_id' => $twin->id, 'location_id' => null, 'qty_this' => 1]],
                '2026-07-01',
                '2026-07-31',
            ));
            $this->fail('Baris kembar A.1 seharusnya sudah kehabisan plafon bersama.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('1.300', $exception->getMessage());
        }
    }

    public function test_blank_item_numbers_group_together_rather_than_each_getting_a_ceiling(): void
    {
        $section = $this->boqItems['A.1']->section_id;

        $blanks = collect(['Mobilisasi alat', 'Demobilisasi alat'])->map(fn (string $description) => BoqItem::query()->create([
            'boq_id' => $this->boq->id,
            'section_id' => $section,
            'wbs_code' => '',
            'description' => $description,
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 5_000_000,
            'amount' => 5_000_000,
        ]));

        // One identity, so one ceiling of 1 + 1 — NOT one each. Grouping unnumbered
        // lines is the conservative reading: the service cannot tell them apart, so
        // it refuses to let either one be measured as if it stood alone.
        $service = $this->service();
        $this->assertSame(2.0, $service->ceilingFor($this->contract, $blanks[0], $this->project));
        $this->assertSame(2.0, $service->ceilingFor($this->contract, $blanks[1], $this->project));
    }

    private function makerUser(): User
    {
        return User::query()->create([
            'name' => 'Pengukur', 'email' => str()->random(8).'@nusantara.test', 'password' => 'password', 'is_active' => true,
        ]);
    }

    private function checkerUser(): User
    {
        return User::query()->create([
            'name' => 'Manajer Proyek', 'email' => str()->random(8).'@nusantara.test', 'password' => 'password', 'is_active' => true,
        ]);
    }
}
