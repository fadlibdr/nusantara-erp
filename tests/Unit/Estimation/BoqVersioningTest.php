<?php

namespace Tests\Unit\Estimation;

use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Estimation\Models\BoqSection;
use Tests\ErpTestCase;

/**
 * Revisi RAB: copyVersion() clones a BOQ into a fresh draft with version + 1.
 * The original document is a signed record and must come out untouched.
 */
class BoqVersioningTest extends ErpTestCase
{
    use EstimationFixtures;

    private Ahsp $concrete;

    private Boq $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->concrete = $this->makeConcreteAhsp();

        $this->original = $this->boqs()->create([
            'title' => 'RAB Gedung Kantor Graha Sentosa',
            'notes' => 'Rev. 0 sesuai gambar tender',
            'sections' => [
                ['section_no' => 'A', 'name' => 'Pekerjaan Persiapan', 'items' => [
                    ['wbs_code' => 'A.1', 'description' => 'Mobilisasi & direksi keet', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 25000000],
                ]],
                ['section_no' => 'B', 'name' => 'Pekerjaan Struktur', 'items' => [
                    ['wbs_code' => 'B.1', 'ahsp_id' => $this->concrete->id, 'qty' => 120],
                    ['wbs_code' => 'B.2', 'description' => 'Pembesian D16', 'qty' => 250, 'unit' => 'kg', 'unit_price' => 85000],
                ]],
            ],
        ]);

        $this->original->submit();
        $this->original->approve($this->makeUser());
    }

    public function test_the_copy_is_a_fresh_draft_with_the_next_version_number(): void
    {
        $copy = $this->boqs()->copyVersion($this->original->refresh());

        $this->assertSame(2, (int) $copy->version);
        $this->assertSame(DocumentStatus::Draft, $copy->status);
        $this->assertNotSame($this->original->id, $copy->id);
        $this->assertNotSame($this->original->code, $copy->code);
        $this->assertStringStartsWith('BOQ/', $copy->code);
    }

    public function test_the_copy_clones_every_section_and_item(): void
    {
        $copy = $this->boqs()->copyVersion($this->original->refresh());

        $this->assertSame(2, $copy->sections()->count());
        $this->assertSame(3, $copy->items()->count());

        $this->assertSame(
            ['A', 'B'],
            $copy->sections()->orderBy('section_no')->pluck('section_no')->all(),
        );
        $this->assertSame(
            ['A.1', 'B.1', 'B.2'],
            $copy->items()->orderBy('wbs_code')->pluck('wbs_code')->all(),
        );

        // Total dan subtotal ikut terbawa: 25.000.000 + 143.647.000 = 168.647.000
        $this->assertSame(168647000.0, (float) $copy->total);
        $this->assertSame([25000000.0, 143647000.0], $copy->sections()->orderBy('section_no')
            ->pluck('subtotal')->map(fn ($subtotal): float => (float) $subtotal)->all());
    }

    public function test_the_cloned_items_keep_their_ahsp_link_and_prices(): void
    {
        $copy = $this->boqs()->copyVersion($this->original->refresh());

        /** @var BoqItem $item */
        $item = $copy->items()->where('wbs_code', 'B.1')->firstOrFail();

        $this->assertSame($this->concrete->id, (int) $item->ahsp_id);
        $this->assertSame(120.0, (float) $item->qty);
        $this->assertSame(1019975.0, (float) $item->unit_price);
        $this->assertSame(122397000.0, (float) $item->amount);
        $this->assertSame('m3', $item->unit);
    }

    public function test_the_cloned_sections_and_items_are_new_rows_not_shared_ones(): void
    {
        $copy = $this->boqs()->copyVersion($this->original->refresh());

        $originalSectionIds = $this->original->sections()->pluck('id')->all();
        $copySectionIds = $copy->sections()->pluck('id')->all();

        $this->assertSame([], array_intersect($originalSectionIds, $copySectionIds));
        $this->assertSame(4, BoqSection::query()->count()); // 2 asli + 2 salinan
        $this->assertSame(6, BoqItem::query()->count());    // 3 asli + 3 salinan
    }

    public function test_the_original_boq_is_untouched_by_the_copy(): void
    {
        $codeBefore = $this->original->code;

        $this->boqs()->copyVersion($this->original->refresh());

        $fresh = Boq::query()->findOrFail($this->original->id);

        $this->assertSame($codeBefore, $fresh->code);
        $this->assertSame(1, (int) $fresh->version);
        $this->assertSame(DocumentStatus::Approved, $fresh->status);
        $this->assertSame(168647000.0, (float) $fresh->total);
        $this->assertSame(3, $fresh->items()->count());
    }

    public function test_editing_the_copy_does_not_touch_the_original(): void
    {
        $copy = $this->boqs()->copyVersion($this->original->refresh());

        $this->boqs()->update($copy, [
            'sections' => [
                ['section_no' => 'A', 'name' => 'Pekerjaan Persiapan', 'items' => [
                    ['wbs_code' => 'A.1', 'description' => 'Mobilisasi (dikurangi)', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 12000000],
                ]],
            ],
        ]);

        $this->assertSame(12000000.0, (float) $copy->refresh()->total);
        $this->assertSame(168647000.0, (float) Boq::query()->findOrFail($this->original->id)->total);
        $this->assertSame(3, Boq::query()->findOrFail($this->original->id)->items()->count());
    }

    public function test_copying_a_copy_reaches_version_three(): void
    {
        $v2 = $this->boqs()->copyVersion($this->original->refresh());
        $v3 = $this->boqs()->copyVersion($v2);

        $this->assertSame(3, (int) $v3->version);
        $this->assertSame(2, (int) $v2->refresh()->version);
        $this->assertSame(1, (int) Boq::query()->findOrFail($this->original->id)->version);
    }

    public function test_the_copy_keeps_the_project_and_contract_links(): void
    {
        $linked = $this->boqs()->create([
            'title' => 'RAB terhubung',
            'project_id' => 77,
            'contract_id' => 88,
            'quotation_id' => 99,
            'sections' => [
                ['section_no' => 'A', 'name' => 'Umum', 'items' => [
                    ['wbs_code' => 'A.1', 'description' => 'Perizinan', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 5000000],
                ]],
            ],
        ]);

        $copy = $this->boqs()->copyVersion($linked);

        $this->assertSame(77, (int) $copy->project_id);
        $this->assertSame(88, (int) $copy->contract_id);
        $this->assertSame(99, (int) $copy->quotation_id);
        $this->assertSame(5000000.0, (float) $copy->total);
    }
}
