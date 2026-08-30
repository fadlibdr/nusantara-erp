<?php

namespace Tests\Unit\Core;

use Illuminate\Support\Carbon;
use Modules\Core\Models\NumberSequence;
use Modules\Core\Services\DocumentNumberService;
use Tests\ErpTestCase;

/**
 * Document numbering: the shape of a number, the token substitution, and the
 * per-type / per-year sequence. Clock is frozen at 15 July 2026, so {Y} = 2026,
 * {M2} = 07 and {RM} = VII throughout unless a test travels elsewhere.
 */
class DocumentNumberServiceTest extends ErpTestCase
{
    private DocumentNumberService $numbers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->numbers = app(DocumentNumberService::class);
        Carbon::setTestNow('2026-07-15 09:00:00');
    }

    public function test_a_purchase_order_number_is_type_year_roman_month_and_four_digits(): void
    {
        // config/erp.php documents.PO = 'PO/{Y}/{RM}/{N4}'; July 2026, first PO.
        $this->assertSame('PO/2026/VII/0001', $this->numbers->next('PO'));
    }

    public function test_the_sequence_increments_on_every_call(): void
    {
        $this->assertSame('PO/2026/VII/0001', $this->numbers->next('PO'));
        $this->assertSame('PO/2026/VII/0002', $this->numbers->next('PO'));
        $this->assertSame('PO/2026/VII/0003', $this->numbers->next('PO'));

        $this->assertDatabaseHas('core_number_sequences', [
            'type' => 'PO',
            'year' => 2026,
            'last_number' => 3,
        ]);
        $this->assertSame(1, NumberSequence::query()->count());
    }

    public function test_sequences_are_independent_per_document_type(): void
    {
        // Two types drawing in an interleaved order must not share a counter.
        $this->assertSame('PO/2026/VII/0001', $this->numbers->next('PO'));
        $this->assertSame('PR/2026/VII/0001', $this->numbers->next('PR'));
        $this->assertSame('PO/2026/VII/0002', $this->numbers->next('PO'));
        $this->assertSame('PR/2026/VII/0002', $this->numbers->next('PR'));
        $this->assertSame('PO/2026/VII/0003', $this->numbers->next('PO'));

        $this->assertSame(3, (int) NumberSequence::query()->where('type', 'PO')->value('last_number'));
        $this->assertSame(2, (int) NumberSequence::query()->where('type', 'PR')->value('last_number'));
    }

    public function test_the_roman_month_token_maps_all_twelve_months(): void
    {
        $roman = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

        foreach ($roman as $month => $numeral) {
            // Same year, so the counter keeps climbing: month 1 => 0001 … month 12 => 0012.
            Carbon::setTestNow(Carbon::create(2026, $month, 10, 8));
            $expected = sprintf('PO/2026/%s/%04d', $numeral, $month);

            $this->assertSame($expected, $this->numbers->next('PO'));
        }

        $this->assertDatabaseHas('core_number_sequences', [
            'type' => 'PO',
            'year' => 2026,
            'last_number' => 12,
        ]);
    }

    public function test_every_token_is_substituted(): void
    {
        $this->setSetting('documents.PO', '{Y}-{M2}-{RM}-{N3}-{N4}-{N5}');

        // 15 July 2026, first number: Y=2026, M2=07, RM=VII, N=1.
        $this->assertSame('2026-07-VII-001-0001-00001', $this->numbers->next('PO'));
    }

    public function test_the_month_token_is_zero_padded_for_single_digit_months(): void
    {
        Carbon::setTestNow('2026-01-05 08:00:00');
        $this->setSetting('documents.JV', 'JV/{Y}/{M2}/{N4}');

        $this->assertSame('JV/2026/01/0001', $this->numbers->next('JV'));
    }

    public function test_the_sequence_tokens_pad_to_three_four_and_five_digits(): void
    {
        NumberSequence::query()->create(['type' => 'PO', 'year' => 2026, 'last_number' => 6]);

        // {Y} is mandatory in every format (M6), so the three widths are compared
        // inside an otherwise valid one rather than on their own.
        $this->setSetting('documents.PO', '{Y}-{N3}-{N4}-{N5}');

        // Seeded at 6, so this call issues 7.
        $this->assertSame('2026-007-0007-00007', $this->numbers->next('PO'));
    }

    public function test_a_sequence_wider_than_its_padding_is_not_truncated(): void
    {
        NumberSequence::query()->create(['type' => 'PO', 'year' => 2026, 'last_number' => 9999]);

        // 9999 + 1 = 10000: five digits in a {N4} slot, str_pad must not cut it.
        $this->assertSame('PO/2026/VII/10000', $this->numbers->next('PO'));
    }

    public function test_an_unknown_type_falls_back_to_the_default_format(): void
    {
        // No documents.ZZZ in config/erp.php => TYPE/{Y}/{RM}/{N4}.
        $this->assertSame('ZZZ/2026/VII/0001', $this->numbers->next('ZZZ'));
    }

    public function test_the_fallback_format_upper_cases_the_type(): void
    {
        $this->assertSame('XY/2026/VII/0001', $this->numbers->next('xy'));
    }

    public function test_the_format_comes_from_the_settings_layer(): void
    {
        $this->setSetting('documents.PO', 'PO-{Y}-{N5}');

        $this->assertSame('PO-2026-00001', $this->numbers->next('PO'));
        $this->assertSame('PO-2026-00002', $this->numbers->next('PO'));
    }

    public function test_changing_the_format_keeps_the_running_sequence(): void
    {
        $this->assertSame('PO/2026/VII/0001', $this->numbers->next('PO'));

        $this->setSetting('documents.PO', 'PO-{Y}-{N5}');

        // The counter is stored per type/year, not per format: 1 then 2.
        $this->assertSame('PO-2026-00002', $this->numbers->next('PO'));
    }

    public function test_the_sequence_restarts_in_a_new_year(): void
    {
        $this->assertSame('PO/2026/VII/0001', $this->numbers->next('PO'));
        $this->assertSame('PO/2026/VII/0002', $this->numbers->next('PO'));

        Carbon::setTestNow('2027-07-15 09:00:00');

        $this->assertSame('PO/2027/VII/0001', $this->numbers->next('PO'));

        $this->assertSame(2, NumberSequence::query()->where('type', 'PO')->count());
        $this->assertSame(2, (int) NumberSequence::query()->where(['type' => 'PO', 'year' => 2026])->value('last_number'));
        $this->assertSame(1, (int) NumberSequence::query()->where(['type' => 'PO', 'year' => 2027])->value('last_number'));
    }

    // ---------------------------------------------------------------- {PROJ} (P8)

    public function test_a_proj_mask_splits_the_counter_per_project(): void
    {
        $this->setSetting('documents.PO', 'PO/{PROJ}/{Y}/{RM}/{N4}');

        // Two projects, interleaved: each carries its own counter, and the
        // rendered code names the project so the unique code column holds.
        $this->assertSame('PO/PRJ-2026-001/2026/VII/0001', $this->numbers->next('PO', 'PRJ-2026-001'));
        $this->assertSame('PO/PRJ-2026-002/2026/VII/0001', $this->numbers->next('PO', 'PRJ-2026-002'));
        $this->assertSame('PO/PRJ-2026-001/2026/VII/0002', $this->numbers->next('PO', 'PRJ-2026-001'));

        $this->assertDatabaseHas('core_number_sequences', ['type' => 'PO', 'year' => 2026, 'scope' => 'PRJ-2026-001', 'last_number' => 2]);
        $this->assertDatabaseHas('core_number_sequences', ['type' => 'PO', 'year' => 2026, 'scope' => 'PRJ-2026-002', 'last_number' => 1]);
        $this->assertSame(2, NumberSequence::query()->count());
    }

    public function test_an_unscoped_sequence_and_a_scoped_one_do_not_collide(): void
    {
        // The migrated-live-data shape: an old scope='' row next to a scoped
        // one. The unscoped counter must keep minting byte-identically.
        NumberSequence::query()->create(['type' => 'PO', 'year' => 2026, 'scope' => 'PRJ-2026-001', 'last_number' => 41]);
        NumberSequence::query()->create(['type' => 'PO', 'year' => 2026, 'scope' => '', 'last_number' => 7]);

        $this->assertSame('PO/2026/VII/0008', $this->numbers->next('PO'));

        $this->assertDatabaseHas('core_number_sequences', ['type' => 'PO', 'scope' => '', 'last_number' => 8]);
        $this->assertDatabaseHas('core_number_sequences', ['type' => 'PO', 'scope' => 'PRJ-2026-001', 'last_number' => 41]);
    }

    public function test_a_scope_under_a_token_less_mask_is_discarded(): void
    {
        // documents.PO has no {PROJ}: a passed scope must not quietly split the
        // counter into buckets no rendered code could tell apart.
        $this->assertSame('PO/2026/VII/0001', $this->numbers->next('PO', 'PRJ-2026-001'));
        $this->assertSame('PO/2026/VII/0002', $this->numbers->next('PO'));

        $this->assertSame(1, NumberSequence::query()->count());
        $this->assertDatabaseHas('core_number_sequences', ['type' => 'PO', 'year' => 2026, 'scope' => '', 'last_number' => 2]);
    }

    public function test_a_proj_mask_without_project_context_refuses_to_mint(): void
    {
        $this->setSetting('documents.PO', 'PO/{PROJ}/{Y}/{RM}/{N4}');

        try {
            $this->numbers->next('PO');
            $this->fail('Expected the mint to be refused.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('memakai token {PROJ}', $e->getMessage());
            $this->assertStringContainsString('Nomor tidak diterbitkan', $e->getMessage());
        }

        // Refused loudly, and no number was burned.
        $this->assertSame(0, NumberSequence::query()->count());
    }

    public function test_each_shipped_document_type_produces_its_configured_shape(): void
    {
        // Spot-check the formats that differ from the PO shape.
        $this->assertSame('PRJ-2026-001', $this->numbers->next('PRJ'));    // PRJ-{Y}-{N3}
        $this->assertSame('BOQ/2026/0001', $this->numbers->next('BOQ'));   // BOQ/{Y}/{N4}
        $this->assertSame('JV/2026/07/0001', $this->numbers->next('JV'));  // JV/{Y}/{M2}/{N4}
        $this->assertSame('PYR/2026/07/001', $this->numbers->next('PYR')); // PYR/{Y}/{M2}/{N3}
        $this->assertSame('TKT-202607-0001', $this->numbers->next('TKT')); // TKT-{Y}{M2}-{N4}
        // AST used to ship as 'AST-{N4}' and collided every January (M6).
        $this->assertSame('AST-2026-0001', $this->numbers->next('AST'));   // AST-{Y}-{N4}
    }
}
