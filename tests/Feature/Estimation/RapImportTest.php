<?php

namespace Tests\Feature\Estimation;

use App\Models\User;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Services\DocumentImportService;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Estimation\Models\CostBudgetItem;
use Modules\Estimation\Services\BoqService;
use Modules\Estimation\Services\RapService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Enums\BacSource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBaseline;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Loading a manually-costed RAP from the estimator's own sheet.
 *
 * The generated RAP already exists: RapService::generateFromBoq deflates every
 * BOQ amount by the target margin and splits it across cost categories from the
 * AHSP mix. This is the other half — the RAP an estimator prices by hand,
 * because the subcontractor quoted a number the analysis does not know.
 *
 * Three things about a RAP make it unlike the other three importable documents,
 * and each of them is a test below. Its parent BOQ is a NOT NULL constrained FK,
 * so a standalone RAP is structurally impossible. Every line points at a BOQ
 * ITEM, and `A.1` means a different piece of work in every bill of quantities in
 * the system — so the lookup is scoped to this RAP's own BOQ or it is wrong.
 * And a RAP is what a project is measured against: prj_baselines freezes a BAC
 * against one, and EvmService reads its lines live, so replacing them is not a
 * private act.
 */
class RapImportTest extends ErpTestCase
{
    private const RESOURCE = 'cost-budgets';

    private DocumentImportService $imports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imports = app(DocumentImportService::class);
    }

    // ------------------------------------------------------------ it lands

    /**
     * 1.500 m2 of site clearing budgeted as two cost lines:
     * upah 1.500 x 4.200 = 6.300.000 and alat 1.500 x 2.800 = 4.200.000,
     * total_budget 10.500.000 — computed by recalcTotals, never taken from the
     * sheet's own jumlah column.
     */
    public function test_a_rap_file_becomes_a_cost_budget_with_its_lines_and_total(): void
    {
        $project = $this->project('PRJ-2026-001');
        $boq = $this->boq('RAB Graha Sentosa', ['A.1' => 1500], $project->id);

        $result = $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'RAP-GRAHA', 'boq_kode' => $boq->code, 'target_margin' => '12'],
            ['tipe' => 'item', 'dokumen' => 'RAP-GRAHA', 'item_boq' => 'A.1', 'kategori' => 'upah',
                'uraian' => 'Pembersihan lahan - tenaga', 'volume' => '1.500', 'satuan' => 'm2',
                'harga_satuan' => '4.200', 'jumlah' => '6.300.000'],
            ['tipe' => 'item', 'dokumen' => 'RAP-GRAHA', 'item_boq' => 'A.1', 'kategori' => 'alat',
                'uraian' => 'Pembersihan lahan - excavator', 'volume' => '1.500', 'satuan' => 'm2',
                'harga_satuan' => '2.800', 'jumlah' => '4.200.000'],
        ));

        $this->assertSame(1, $result['created'], json_encode($result['documents']));

        $budget = CostBudget::query()->sole();

        $this->assertSame($boq->id, $budget->boq_id);
        // proyek_kode was left blank, so the RAP follows its BOQ's project —
        // which is how prj_baselines finds a project's RAP at all.
        $this->assertSame($project->id, $budget->project_id);
        $this->assertSame(DocumentStatus::Draft, $budget->status);
        $this->assertStringStartsWith('RAP/', $budget->code);
        $this->assertEqualsWithDelta(12.0, (float) $budget->target_margin_pct, 0.0001);

        $this->assertSame(['labor', 'equipment'], $budget->items()
            ->get()->map(fn (CostBudgetItem $item) => $item->cost_category->value)->all());
        // 1.500 in a volume column is fifteen hundred, not one-and-a-half.
        $this->assertEqualsWithDelta(1500.0, (float) $budget->items()->first()->qty, 0.001);
        $this->assertEqualsWithDelta(6_300_000.0, (float) $budget->items()->first()->amount, 0.01);
        $this->assertEqualsWithDelta(10_500_000.0, (float) $budget->total_budget, 0.01);

        $this->assertSame($budget->code, $result['codes']['RAP-GRAHA']);
    }

    /** The words an estimator writes in a kategori column, not the words the enum stores. */
    public function test_kategori_accepts_the_words_an_estimator_writes(): void
    {
        $boq = $this->boq('RAB Kategori', ['A.1' => 1]);

        $rows = [['tipe' => 'dokumen', 'dokumen' => 'RAP-A', 'boq_kode' => $boq->code, 'target_margin' => '10']];

        foreach (['bahan', 'upah', 'subkon', 'alat', 'overhead'] as $word) {
            $rows[] = ['tipe' => 'item', 'dokumen' => 'RAP-A', 'item_boq' => 'A.1', 'kategori' => $word,
                'uraian' => "Bagian {$word}", 'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'];
        }

        $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(...$rows));

        $this->assertSame(
            ['material', 'labor', 'subcon', 'equipment', 'overhead'],
            CostBudget::query()->sole()->items()->get()
                ->map(fn (CostBudgetItem $item) => $item->cost_category->value)->all(),
        );
    }

    /**
     * RapService::splitBudget itself emits three to five category lines per BOQ
     * item, so a repeated item_boq is the ordinary case and not an error.
     */
    public function test_several_lines_may_budget_the_same_boq_item(): void
    {
        $boq = $this->boq('RAB Berulang', ['A.1' => 10]);

        $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'RAP-A', 'boq_kode' => $boq->code, 'target_margin' => '10'],
            ['tipe' => 'item', 'dokumen' => 'RAP-A', 'item_boq' => 'A.1', 'kategori' => 'bahan',
                'uraian' => 'Material', 'volume' => '10', 'satuan' => 'm3', 'harga_satuan' => '100.000'],
            ['tipe' => 'item', 'dokumen' => 'RAP-A', 'item_boq' => 'A.1', 'kategori' => 'upah',
                'uraian' => 'Tenaga', 'volume' => '10', 'satuan' => 'm3', 'harga_satuan' => '50.000'],
        ));

        $itemId = $boq->items()->where('wbs_code', 'A.1')->value('id');

        $this->assertSame(2, CostBudgetItem::query()->where('boq_item_id', $itemId)->count());
    }

    // ---------------------------------------------------------- the scoping

    /**
     * A.1 exists in every BOQ in the system, so an unscoped lookup would put a
     * cost line against another project's work and nothing downstream would say
     * so — est_boq_items.wbs_code is unique to nothing at the database level.
     */
    public function test_item_boq_resolves_inside_this_raps_own_boq(): void
    {
        $first = $this->boq('RAB Pertama', ['A.1' => 1]);
        $second = $this->boq('RAB Kedua', ['A.1' => 1]);

        $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'RAP-KEDUA', 'boq_kode' => $second->code, 'target_margin' => '10'],
            ['tipe' => 'item', 'dokumen' => 'RAP-KEDUA', 'item_boq' => 'A.1', 'kategori' => 'bahan',
                'uraian' => 'Material', 'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
        ));

        $bound = CostBudgetItem::query()->sole()->boqItem;

        $this->assertSame($second->id, $bound->boq_id);
        $this->assertNotSame($first->items()->where('wbs_code', 'A.1')->value('id'), $bound->id);
    }

    /**
     * A RAP that silently drops the lines for three BOQ items understates the
     * budget, and every variance report written against it is wrong forever —
     * so one unresolvable line refuses the whole document.
     */
    public function test_an_item_boq_that_is_not_in_this_boq_refuses_the_whole_rap(): void
    {
        $boq = $this->boq('RAB Sebagian', ['A.1' => 1]);

        $result = $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'RAP-A', 'boq_kode' => $boq->code, 'target_margin' => '10'],
            ['tipe' => 'item', 'dokumen' => 'RAP-A', 'item_boq' => 'A.1', 'kategori' => 'bahan',
                'uraian' => 'Baris yang baik', 'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
            ['tipe' => 'item', 'dokumen' => 'RAP-A', 'item_boq' => 'A.9', 'kategori' => 'bahan',
                'uraian' => 'Baris yang tidak ada di BOQ', 'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '2.000.000'],
        ));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString(
            'item_boq: "A.9" tidak ditemukan',
            implode(' ', $result['documents'][0]['rows'][1]['errors']),
        );

        // Not even the good line survived: a half-written RAP is worse than none.
        $this->assertSame(0, CostBudget::query()->count());
        $this->assertSame(0, CostBudgetItem::query()->count());
    }

    /**
     * Nothing stops two sections of one BOQ carrying the same wbs_code. Binding
     * to the first match would put a whole cost line against the wrong work item
     * and no screen would ever disagree with it.
     */
    public function test_an_ambiguous_item_boq_is_refused_rather_than_bound_to_the_first(): void
    {
        $boq = app(BoqService::class)->create([
            'title' => 'RAB Nomor Kembar',
            'sections' => [
                ['section_no' => 'A', 'name' => 'Persiapan', 'items' => [
                    ['wbs_code' => 'A.1', 'description' => 'Pembersihan lahan', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 1000],
                ]],
                ['section_no' => 'B', 'name' => 'Struktur', 'items' => [
                    ['wbs_code' => 'A.1', 'description' => 'Salah ketik nomor', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 1000],
                ]],
            ],
        ]);

        $result = $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'RAP-A', 'boq_kode' => $boq->code, 'target_margin' => '10'],
            ['tipe' => 'item', 'dokumen' => 'RAP-A', 'item_boq' => 'A.1', 'kategori' => 'bahan',
                'uraian' => 'Material', 'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
        ));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString(
            'cocok dengan 2 baris',
            implode(' ', $result['documents'][0]['rows'][0]['errors']),
        );
    }

    /**
     * est_cost_budgets.boq_id is a NOT NULL constrained FK: a RAP with no BOQ
     * cannot exist, so an unknown code refuses rather than creating an orphan.
     */
    public function test_an_unknown_boq_kode_refuses_the_rap(): void
    {
        $result = $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'RAP-A', 'boq_kode' => 'BOQ/2026/9999', 'target_margin' => '10'],
            ['tipe' => 'item', 'dokumen' => 'RAP-A', 'item_boq' => 'A.1', 'kategori' => 'bahan',
                'uraian' => 'Material', 'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
        ));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString(
            'boq_kode: "BOQ/2026/9999" tidak ditemukan',
            implode(' ', $result['documents'][0]['errors']),
        );
        $this->assertSame(0, CostBudget::query()->count());
    }

    /**
     * No FormRequest describes a RAP line — CostBudgetStoreRequest covers the
     * header and stops — so the definition's own per-column rules are the only
     * thing between a zero-volume line and a budget row worth nothing.
     */
    public function test_a_line_with_no_volume_is_refused_by_the_definitions_own_rules(): void
    {
        $boq = $this->boq('RAB Volume Nol', ['A.1' => 1]);

        $result = $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'RAP-A', 'boq_kode' => $boq->code, 'target_margin' => '10'],
            ['tipe' => 'item', 'dokumen' => 'RAP-A', 'item_boq' => 'A.1', 'kategori' => 'bahan',
                'uraian' => 'Material', 'volume' => '0', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
        ));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('volume', implode(' ', $result['documents'][0]['rows'][0]['errors']));
    }

    // --------------------------------------------------------- what it guards

    /**
     * An approved RAP is the budget a project is measured against; the import
     * must not be the door round the approval.
     */
    public function test_an_approved_rap_is_never_overwritten(): void
    {
        $boq = $this->boq('RAB Disetujui', ['A.1' => 1]);
        $budget = $this->rap($boq, [['A.1', 'material', 'Material awal', 1, 5_000_000]]);
        $budget->forceFill(['status' => DocumentStatus::Approved])->save();

        $result = $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => $budget->code, 'boq_kode' => $boq->code, 'target_margin' => '30'],
            ['tipe' => 'item', 'dokumen' => $budget->code, 'item_boq' => 'A.1', 'kategori' => 'bahan',
                'uraian' => 'Diam-diam diganti', 'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '9.000.000'],
        ));

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('Disetujui', implode(' ', $result['documents'][0]['errors']));

        $budget->refresh();
        $this->assertSame(['Material awal'], $budget->items()->pluck('description')->all());
        $this->assertEqualsWithDelta(5_000_000.0, (float) $budget->total_budget, 0.01);
    }

    /**
     * The guard above must live in the service, not in its caller.
     *
     * CostBudget's other editability check sits in CostBudgetController::update,
     * so a service method that wrote lines without one of its own would make
     * whichever caller forgot it — the importer first — the supported way to
     * rewrite an approved RAP.
     */
    public function test_replace_items_refuses_an_approved_rap_at_the_service_level(): void
    {
        $boq = $this->boq('RAB Layanan', ['A.1' => 1]);
        $budget = $this->rap($boq, [['A.1', 'material', 'Material awal', 1, 5_000_000]]);
        $budget->forceFill(['status' => DocumentStatus::Approved])->save();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/cannot be rewritten while status is approved/');

        app(RapService::class)->replaceItems($budget, []);
    }

    /** A draft RAP is the case the guard above must not also refuse. */
    public function test_a_draft_rap_is_updated_in_place_and_its_lines_replaced(): void
    {
        $boq = $this->boq('RAB Draf', ['A.1' => 1, 'A.2' => 1]);
        $budget = $this->rap($boq, [
            ['A.1', 'material', 'Baris lama satu', 1, 1_000_000],
            ['A.2', 'labor', 'Baris lama dua', 1, 2_000_000],
        ]);

        $result = $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => $budget->code, 'boq_kode' => $boq->code,
                'target_margin' => '15', 'catatan' => 'Revisi setelah penawaran subkon masuk'],
            ['tipe' => 'item', 'dokumen' => $budget->code, 'item_boq' => 'A.1', 'kategori' => 'subkon',
                'uraian' => 'Baris baru', 'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '7.500.000'],
        ));

        $this->assertSame(1, $result['updated'], json_encode($result['documents']));
        $this->assertSame(1, CostBudget::query()->count(), 'an update must never mint a second RAP');

        $budget->refresh();
        $this->assertSame(['Baris baru'], $budget->items()->pluck('description')->all());
        $this->assertEqualsWithDelta(15.0, (float) $budget->target_margin_pct, 0.0001);
        $this->assertSame('Revisi setelah penawaran subkon masuk', $budget->notes);
        // recalcTotals ran on the new lines, not on the old ones.
        $this->assertEqualsWithDelta(7_500_000.0, (float) $budget->total_budget, 0.01);
    }

    /**
     * One mistyped boq_kode on an update row would otherwise leave a RAP whose
     * every line points into a BOQ it no longer belongs to, and whose variance
     * is measured against a different bill of quantities.
     */
    public function test_a_rap_cannot_be_moved_to_another_boq_by_re_import(): void
    {
        $own = $this->boq('RAB Miliknya', ['A.1' => 1]);
        $other = $this->boq('RAB Orang Lain', ['A.1' => 1]);
        $budget = $this->rap($own, [['A.1', 'material', 'Material', 1, 1_000_000]]);

        $result = $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => $budget->code, 'boq_kode' => $other->code, 'target_margin' => '10'],
            ['tipe' => 'item', 'dokumen' => $budget->code, 'item_boq' => 'A.1', 'kategori' => 'bahan',
                'uraian' => 'Material', 'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
        ));

        $this->assertSame(0, $result['updated']);
        $this->assertStringContainsString(
            'tidak dapat dipindahkan ke BOQ lain',
            implode(' ', $result['documents'][0]['errors']),
        );
        $this->assertSame($own->id, $budget->refresh()->boq_id);
    }

    /**
     * prj_baselines freezes a BAC against one RAP, but EvmService::costCoverage
     * reads that RAP's LINES live — and BacSource::RapUnapproved exists because
     * a baseline may legitimately freeze against a RAP that is still a draft.
     * Rewriting the draft would move the yardstick under a frozen baseline
     * without one screen saying so.
     */
    public function test_a_rap_frozen_into_an_approved_baseline_refuses_re_import(): void
    {
        $project = $this->project('PRJ-2026-001');
        $boq = $this->boq('RAB Baseline', ['A.1' => 1], $project->id);
        $budget = $this->rap($boq, [['A.1', 'material', 'Material', 1, 1_000_000]]);

        $this->baseline($project, $budget, DocumentStatus::Approved);

        $result = $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => $budget->code, 'boq_kode' => $boq->code, 'target_margin' => '10'],
            ['tipe' => 'item', 'dokumen' => $budget->code, 'item_boq' => 'A.1', 'kategori' => 'bahan',
                'uraian' => 'Anggaran digeser', 'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '9.000.000'],
        ));

        $this->assertSame(0, $result['updated']);
        $this->assertStringContainsString(
            'sudah dibekukan terhadap RAP ini',
            implode(' ', $result['documents'][0]['errors']),
        );
        $this->assertSame(['Material'], $budget->refresh()->items()->pluck('description')->all());
    }

    /**
     * A baseline that is still a draft has frozen nothing, so it must not block
     * the ordinary case of pricing a RAP while the plan is still being written.
     */
    public function test_a_baseline_still_in_draft_does_not_block_the_import(): void
    {
        $project = $this->project('PRJ-2026-001');
        $boq = $this->boq('RAB Baseline Draf', ['A.1' => 1], $project->id);
        $budget = $this->rap($boq, [['A.1', 'material', 'Material', 1, 1_000_000]]);

        $this->baseline($project, $budget, DocumentStatus::Draft);

        $result = $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => $budget->code, 'boq_kode' => $boq->code, 'target_margin' => '10'],
            ['tipe' => 'item', 'dokumen' => $budget->code, 'item_boq' => 'A.1', 'kategori' => 'bahan',
                'uraian' => 'Harga subkon final', 'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '9.000.000'],
        ));

        $this->assertSame(1, $result['updated'], json_encode($result['documents']));
        $this->assertEqualsWithDelta(9_000_000.0, (float) $budget->refresh()->total_budget, 0.01);
    }

    // ------------------------------------------------------------ endpoints

    /** Importing a RAP is an est right, and create alone is not enough. */
    public function test_importing_a_rap_needs_est_create_and_update(): void
    {
        $boq = $this->boq('RAB Izin', ['A.1' => 1]);

        $payload = ['filename' => 'rap.csv', 'content' => $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'RAP-A', 'boq_kode' => $boq->code, 'target_margin' => '10'],
            ['tipe' => 'item', 'dokumen' => 'RAP-A', 'item_boq' => 'A.1', 'kategori' => 'bahan',
                'uraian' => 'Material', 'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
        )];

        $this->actingAs($this->userWithOnly(['est.view', 'est.create']))
            ->postJson('/api/core/document-import/'.self::RESOURCE.'/import', $payload)
            ->assertForbidden();

        $this->assertSame(0, CostBudget::query()->count());

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/api/core/document-import/'.self::RESOURCE.'/preview', $payload)
            ->assertOk()
            ->assertJsonPath('data.summary.to_create', 1);

        $this->assertSame(0, CostBudget::query()->count(), 'a preview writes nothing');

        $this->actingAs($admin)
            ->postJson('/api/core/document-import/'.self::RESOURCE.'/import', $payload)
            ->assertOk()
            ->assertJsonPath('data.created', 1);
    }

    /**
     * The export is what makes a create-import recoverable, and a RAP is the
     * document where the round trip is hardest: a volume of 1.500 written back
     * as "1.500" would re-import as one and a half.
     */
    public function test_the_export_returns_a_file_the_importer_accepts_back(): void
    {
        $boq = $this->boq('RAB Bolak-balik', ['A.1' => 1500]);

        $this->imports->commit(self::RESOURCE, 'rap.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'RAP-A', 'boq_kode' => $boq->code, 'target_margin' => '10'],
            ['tipe' => 'item', 'dokumen' => 'RAP-A', 'item_boq' => 'A.1', 'kategori' => 'bahan',
                'uraian' => 'Material', 'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '4.200'],
        ));

        $csv = $this->imports->export(self::RESOURCE);
        $result = $this->imports->commit(self::RESOURCE, 'ekspor.csv', base64_encode($csv));

        $this->assertSame(1, $result['updated'], json_encode($result['documents']));
        $this->assertSame(1, CostBudget::query()->count());
        $this->assertEqualsWithDelta(1500.0, (float) CostBudgetItem::query()->sole()->qty, 0.001);
        $this->assertEqualsWithDelta(6_300_000.0, (float) CostBudget::query()->sole()->total_budget, 0.01);
    }

    // -------------------------------------------------------------- fixtures

    private function project(string $code): Project
    {
        return Project::query()->create([
            'code' => $code, 'name' => "Proyek {$code}", 'type' => 'construction', 'status' => 'active',
        ]);
    }

    /**
     * One BOQ, one bagian, and the wbs codes asked for.
     *
     * @param  array<string, float>  $items  wbs_code => volume
     */
    private function boq(string $title, array $items, ?int $projectId = null): Boq
    {
        $lines = [];

        foreach ($items as $wbs => $qty) {
            $lines[] = ['wbs_code' => $wbs, 'description' => "Pekerjaan {$wbs}", 'qty' => $qty,
                'unit' => 'm2', 'unit_price' => 12_500];
        }

        return app(BoqService::class)->create([
            'title' => $title,
            'project_id' => $projectId,
            'sections' => [['section_no' => 'A', 'name' => 'Pekerjaan Persiapan', 'items' => $lines]],
        ]);
    }

    /**
     * A RAP built through the service, so the tests that assert an import cannot
     * change it are asserting against a document the import did not make.
     *
     * @param  array<int, array{0: string, 1: string, 2: string, 3: float, 4: float}>  $lines
     */
    private function rap(Boq $boq, array $lines): CostBudget
    {
        $service = app(RapService::class);

        $budget = $service->create(['boq_id' => $boq->id, 'target_margin_pct' => 10]);

        $service->replaceItems($budget, array_map(fn (array $line): array => [
            'boq_item_id' => $boq->items()->where('wbs_code', $line[0])->value('id'),
            'cost_category' => $line[1],
            'description' => $line[2],
            'qty' => $line[3],
            'unit' => 'ls',
            'unit_price' => $line[4],
        ], $lines));

        return $budget->refresh();
    }

    private function baseline(Project $project, CostBudget $budget, DocumentStatus $status): ProjectBaseline
    {
        return ProjectBaseline::query()->create([
            'project_id' => $project->id,
            'revision_no' => 0,
            'status' => $status,
            'effective_date' => '2026-02-02',
            'bac' => $budget->total_budget,
            'bac_source' => BacSource::RapUnapproved,
            'cost_budget_id' => $budget->id,
            'cost_budget_code' => $budget->code,
            'cost_budget_status' => $budget->status->value,
            'planned_start' => '2026-02-02',
            'planned_finish' => '2027-07-31',
            'planned_duration_days' => 545,
            'leaf_task_count' => 1,
            'leaf_weight_total' => 100,
        ]);
    }

    /**
     * A base64 CSV whose rows are keyed by column heading, so a test says what
     * it means instead of counting commas.
     *
     * The headings come from the SHIPPED TEMPLATE's own first line, never from a
     * list typed in here. A test carrying its own copy would go on passing after
     * the registry renamed a column, while the template an operator downloads
     * had stopped matching what the importer reads — and the symptom of that is
     * an import that lands nothing and explains nothing.
     *
     * @param  array<string, string>  ...$rows
     */
    private function file(array ...$rows): string
    {
        $headers = str_getcsv(
            (string) strtok($this->imports->template(self::RESOURCE), "\n"), ',', '"', '\\',
        );
        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            $cells = [];

            foreach ($headers as $header) {
                $value = (string) ($row[$header] ?? '');
                $cells[] = str_contains($value, ',') ? '"'.$value.'"' : $value;
            }

            $lines[] = implode(',', $cells);
        }

        return base64_encode(implode("\n", $lines)."\n");
    }

    private function userWithOnly(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('terbatas', 'web');
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Estimator', 'email' => 'estimator@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
