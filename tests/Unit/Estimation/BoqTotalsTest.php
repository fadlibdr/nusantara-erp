<?php

namespace Tests\Unit\Estimation;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Tests\ErpTestCase;

/**
 * BOQ / RAB arithmetic:
 *
 *   item amount     = qty * unit_price
 *   section subtotal = sum(item amounts in the section)
 *   boq total       = sum(section subtotals)
 *
 * An item priced from an AHSP inherits that analysis' unit price and unit.
 */
class BoqTotalsTest extends ErpTestCase
{
    use EstimationFixtures;

    private Ahsp $concrete;

    protected function setUp(): void
    {
        parent::setUp();

        // unit_price 1.019.975 per m3 (see EstimationFixtures for the analysis)
        $this->concrete = $this->makeConcreteAhsp();
    }

    /**
     * A. Pekerjaan Persiapan  : 1 ls x 25.000.000            = 25.000.000
     * B. Pekerjaan Struktur   : 120 m3 x 1.019.975 (AHSP)    = 122.397.000
     *                           250 kg x 85.000              =  21.250.000
     */
    private function makeBoq(array $overrides = []): Boq
    {
        return $this->boqs()->create(array_merge([
            'title' => 'RAB Gedung Kantor Graha Sentosa',
            'sections' => [
                [
                    'section_no' => 'A',
                    'name' => 'Pekerjaan Persiapan',
                    'items' => [
                        ['wbs_code' => 'A.1', 'description' => 'Mobilisasi & direksi keet', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 25000000],
                    ],
                ],
                [
                    'section_no' => 'B',
                    'name' => 'Pekerjaan Struktur',
                    'items' => [
                        ['wbs_code' => 'B.1', 'ahsp_id' => $this->concrete->id, 'qty' => 120],
                        ['wbs_code' => 'B.2', 'description' => 'Pembesian D16', 'qty' => 250, 'unit' => 'kg', 'unit_price' => 85000],
                    ],
                ],
            ],
        ], $overrides));
    }

    public function test_item_amount_is_qty_times_unit_price(): void
    {
        $boq = $this->makeBoq();

        $amounts = $boq->items()->orderBy('wbs_code')->pluck('amount')
            ->map(fn ($amount): float => (float) $amount)->all();

        // 1 x 25.000.000 ; 120 x 1.019.975 ; 250 x 85.000
        $this->assertSame([25000000.0, 122397000.0, 21250000.0], $amounts);
    }

    public function test_an_item_priced_from_an_ahsp_takes_its_unit_price_unit_and_name(): void
    {
        $boq = $this->makeBoq();

        /** @var BoqItem $item */
        $item = $boq->items()->where('wbs_code', 'B.1')->firstOrFail();

        $this->assertSame($this->concrete->id, (int) $item->ahsp_id);
        $this->assertSame(1019975.0, (float) $item->unit_price);
        $this->assertSame('m3', $item->unit);
        $this->assertSame('Beton K-300 (site mix)', $item->description);
        // 120 x 1.019.975 = 122.397.000
        $this->assertSame(122397000.0, (float) $item->amount);
    }

    public function test_an_explicit_unit_price_overrides_the_ahsp_price(): void
    {
        $boq = $this->makeBoq([
            'sections' => [
                [
                    'section_no' => 'B',
                    'name' => 'Pekerjaan Struktur',
                    'items' => [
                        // Ready mix, bukan site mix: harga negosiasi supplier.
                        ['wbs_code' => 'B.1', 'ahsp_id' => $this->concrete->id, 'qty' => 120, 'unit_price' => 950000],
                    ],
                ],
            ],
        ]);

        /** @var BoqItem $item */
        $item = $boq->items()->firstOrFail();

        $this->assertSame(950000.0, (float) $item->unit_price);
        // Satuan tetap ikut AHSP karena tidak ditulis ulang.
        $this->assertSame('m3', $item->unit);
        // 120 x 950.000 = 114.000.000
        $this->assertSame(114000000.0, (float) $item->amount);
    }

    public function test_an_item_without_an_ahsp_or_a_unit_falls_back_to_lump_sum(): void
    {
        $boq = $this->boqs()->create([
            'title' => 'RAB minimal',
            'sections' => [
                ['section_no' => 'A', 'name' => 'Umum', 'items' => [
                    ['wbs_code' => 'A.1', 'description' => 'Biaya perizinan', 'qty' => 1, 'unit_price' => 7500000],
                ]],
            ],
        ]);

        $this->assertSame('ls', $boq->items()->firstOrFail()->unit);
        $this->assertSame(7500000.0, (float) $boq->total);
    }

    public function test_section_subtotals_are_the_sum_of_their_own_items(): void
    {
        $boq = $this->makeBoq();

        $subtotals = $boq->sections()->orderBy('section_no')->pluck('subtotal')
            ->map(fn ($subtotal): float => (float) $subtotal)->all();

        // A: 25.000.000 ; B: 122.397.000 + 21.250.000 = 143.647.000
        $this->assertSame([25000000.0, 143647000.0], $subtotals);
    }

    public function test_boq_total_is_the_sum_of_the_section_subtotals(): void
    {
        $boq = $this->makeBoq();

        // 25.000.000 + 143.647.000 = 168.647.000
        $this->assertSame(168647000.0, (float) $boq->total);
    }

    public function test_an_empty_boq_totals_zero(): void
    {
        $boq = $this->boqs()->create(['title' => 'RAB kosong', 'sections' => []]);

        $this->assertSame(0.0, (float) $boq->total);
        $this->assertSame(0, $boq->sections()->count());
        $this->assertStringStartsWith('BOQ/', $boq->code);
        $this->assertSame(DocumentStatus::Draft, $boq->status);
        $this->assertSame(1, (int) $boq->version);
    }

    public function test_item_amounts_are_rounded_to_the_cent(): void
    {
        $boq = $this->boqs()->create([
            'title' => 'RAB pembulatan',
            'sections' => [
                ['section_no' => 'A', 'name' => 'Uji', 'items' => [
                    ['wbs_code' => 'A.1', 'description' => 'Kabel', 'qty' => 3.333, 'unit' => 'm', 'unit_price' => 12500.55],
                ]],
            ],
        ]);

        // 3,333 x 12.500,55 = 41.664,33315 -> 41.664,33
        $this->assertSame(41664.33, (float) $boq->items()->firstOrFail()->amount);
        $this->assertSame(41664.33, (float) $boq->total);
    }

    public function test_replacing_the_sections_wipes_the_old_items_and_retotals(): void
    {
        $boq = $this->makeBoq();

        $this->boqs()->update($boq, [
            'sections' => [
                ['section_no' => 'A', 'name' => 'Pekerjaan Persiapan', 'items' => [
                    ['wbs_code' => 'A.1', 'description' => 'Mobilisasi', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 10000000],
                ]],
            ],
        ]);

        $boq->refresh();

        $this->assertSame(1, $boq->sections()->count());
        $this->assertSame(1, $boq->items()->count());
        $this->assertSame(1, BoqItem::query()->count());
        $this->assertSame(10000000.0, (float) $boq->total);
    }

    public function test_importing_items_from_ahsp_appends_and_retotals(): void
    {
        $boq = $this->makeBoq();
        $section = $boq->sections()->where('section_no', 'B')->firstOrFail();

        $this->boqs()->importItemsFromAhsp($boq, $section, [
            ['ahsp_id' => $this->concrete->id, 'wbs_code' => 'B.3', 'qty' => 30],
        ]);

        $boq->refresh();

        // 30 x 1.019.975 = 30.599.250 ditambahkan ke seksi B (baris lama tetap ada)
        $this->assertSame(4, $boq->items()->count());
        $this->assertSame(174246250.0, (float) $boq->sections()->where('section_no', 'B')->firstOrFail()->subtotal);
        // 25.000.000 + 143.647.000 + 30.599.250 = 199.246.250
        $this->assertSame(199246250.0, (float) $boq->total);
    }

    public function test_editing_an_approved_boq_throws_and_changes_nothing(): void
    {
        $boq = $this->makeBoq();
        $boq->submit();
        $boq->approve($this->makeUser());

        try {
            $this->boqs()->update($boq, ['title' => 'Diubah diam-diam', 'sections' => []]);
            $this->fail('Expected LogicException when editing an approved BOQ.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('cannot be edited', $e->getMessage());
        }

        $fresh = Boq::query()->findOrFail($boq->id);

        $this->assertSame('RAB Gedung Kantor Graha Sentosa', $fresh->title);
        $this->assertSame(2, $fresh->sections()->count());
        $this->assertSame(168647000.0, (float) $fresh->total);
    }

    public function test_recalc_repairs_totals_that_drifted(): void
    {
        $boq = $this->makeBoq();

        Boq::query()->whereKey($boq->id)->update(['total' => 1]);

        $this->boqs()->recalcTotals($boq->refresh());

        $this->assertSame(168647000.0, (float) $boq->refresh()->total);
    }
}
