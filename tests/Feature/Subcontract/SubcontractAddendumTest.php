<?php

namespace Tests\Feature\Subcontract;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Subcontract\Models\Subcontract;
use Modules\Subcontract\Models\SubcontractAddendum;
use Modules\Subcontract\Services\AddendumService;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * Addendum SPK — temuan #48.
 *
 * An approved SPK was permanently frozen: assertEditable refuses edits and the
 * klaim plafon (assertWithinContractValue) stayed at the signing-day value, so
 * field scope changes forced a SECOND unrelated SPK and split one paket
 * pekerjaan's progress, retention and evaluation across two documents.
 *
 * What matters here mirrors the CCO suite: the SPK's history survives the
 * amendment (original_value), a reduction can never take the SPK below what
 * approved opnames already claimed, and — the part CCO does not have — the
 * plafon the opnames are checked against moves in the same transaction, and
 * an addendum cannot sneak a commitment past the SPK's own director gate.
 */
class SubcontractAddendumTest extends ErpTestCase
{
    use SubcontractFixtures;

    private AddendumService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AddendumService::class);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * SPK 100 juta, satu baris, disetujui — the state an addendum amends.
     * Deliberately BELOW the Rp 200 juta director threshold: the plain
     * approver() fixture holds no permissions, so any fixture whose
     * post-addendum value crossed the threshold would trip the director gate
     * in tests that are about something else entirely.
     */
    private function approvedSpk(float $value = 100_000_000.0): array
    {
        $spk = $this->makeApprovedSubcontract(['value' => $value]);
        $line = $this->addLine($spk, [
            'description' => 'Pekerjaan struktur beton',
            'qty' => 1,
            'unit_price' => $value,
            'amount' => $value,
        ]);

        return [$spk, $line];
    }

    private function draftAddendum(Subcontract $spk, float $change, array $extra = []): SubcontractAddendum
    {
        $data = array_merge([
            'subcontract_id' => $spk->id,
            'addendum_date' => '2026-06-01',
            'title' => $change >= 0 ? 'Tambah pekerjaan ME lantai 2' : 'Kurang pekerjaan lansekap',
            'reason' => 'kondisi_lapangan',
            'value_change' => $change,
        ], $extra);

        if ($change > 0 && ! array_key_exists('items', $data)) {
            $data['items'] = [[
                'description' => 'Pekerjaan tambahan ME lantai 2',
                'qty' => 1,
                'unit' => 'ls',
                'unit_price' => $change,
            ]];
        }

        return $this->service->create($data);
    }

    private function approveAddendum(SubcontractAddendum $addendum): SubcontractAddendum
    {
        // Prepared by one person, agreed by another: a document that moves the
        // klaim plafon is precisely what maker-checker exists for.
        $addendum->submit($this->actor());

        return $this->service->approve($addendum->refresh(), $this->approver());
    }

    // ------------------------------------------------- value & plafon movement

    public function test_added_scope_raises_the_spk_value_and_appends_its_lines_on_approval(): void
    {
        [$spk] = $this->approvedSpk();
        $addendum = $this->draftAddendum($spk, 50_000_000);

        $this->assertEqualsWithDelta(100_000_000, (float) $spk->refresh()->value, 0.01, 'nothing moves before approval');
        $this->assertSame(1, $spk->items()->count(), 'no line appears before approval');

        $this->approveAddendum($addendum);

        $spk->refresh();
        $this->assertEqualsWithDelta(150_000_000, (float) $spk->value, 0.01);
        $this->assertEqualsWithDelta(100_000_000, (float) $spk->original_value, 0.01, 'what was signed stays readable');

        // items() already orders by line_no ascending, so ask for the row
        // rather than fighting the relation's own ORDER BY.
        $appended = $spk->items()->where('line_no', 2)->sole();
        $this->assertSame('Pekerjaan tambahan ME lantai 2', $appended->description);
        $this->assertSame(2, (int) $appended->line_no);
        $this->assertEqualsWithDelta(0.0, (float) $appended->progress_pct, 0.0001, 'new scope starts unclaimed');
        $this->assertEqualsWithDelta(50_000_000, (float) $appended->amount, 0.01);
    }

    /**
     * The point of the whole feature: the klaim plafon follows the addendum.
     * Before it, cumulative opname was locked at the signing-day 200 juta and
     * the added work could never be claimed.
     */
    public function test_the_addendum_line_is_claimable_past_the_original_value(): void
    {
        [$spk, $line] = $this->approvedSpk();
        $this->approveAddendum($this->draftAddendum($spk, 50_000_000));

        $addendumLine = $spk->items()->where('line_no', 2)->sole();

        // 100% of both lines = 150 juta > the original 100 juta plafon.
        $claim = $this->approvedClaim($spk->refresh(), [
            $line->id => 100,
            $addendumLine->id => 100,
        ]);

        $this->assertSame(DocumentStatus::Approved, $claim->status);
        $this->assertEqualsWithDelta(150_000_000, (float) $claim->gross_amount, 0.01);
    }

    public function test_a_reduction_lowers_the_plafon_too(): void
    {
        [$spk, $line] = $this->approvedSpk();
        $this->approveAddendum($this->draftAddendum($spk, -50_000_000));

        $this->assertEqualsWithDelta(50_000_000, (float) $spk->refresh()->value, 0.01);

        // The untouched line still sums to 100 juta, but only 50 juta of it
        // is claimable now — the plafon guard holds the difference back.
        try {
            $this->approvedClaim($spk, [$line->id => 100]);
            $this->fail('an opname past the reduced value must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('exceeds the remaining SPK value', $e->getMessage());
        }
    }

    public function test_a_reduction_below_claimed_work_is_refused(): void
    {
        [$spk, $line] = $this->approvedSpk();
        $this->approvedClaim($spk, [$line->id => 50]); // 50 juta claimed

        $addendum = $this->draftAddendum($spk, -60_000_000); // would leave 40 juta
        $addendum->submit($this->actor());

        try {
            $this->service->approve($addendum->refresh(), $this->approver());
            $this->fail('a reduction below claimed opname must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('lebih kecil daripada yang sudah diopname', $e->getMessage());
        }

        $this->assertEqualsWithDelta(100_000_000, (float) $spk->refresh()->value, 0.01, 'the refusal moved nothing');
    }

    public function test_original_value_is_backfilled_once_not_overwritten_per_addendum(): void
    {
        [$spk] = $this->approvedSpk();

        $this->approveAddendum($this->draftAddendum($spk, 50_000_000));
        $this->approveAddendum($this->draftAddendum($spk, 25_000_000, ['title' => 'Tambah pekerjaan tahap 2']));

        $spk->refresh();
        $this->assertEqualsWithDelta(175_000_000, (float) $spk->value, 0.01);
        $this->assertEqualsWithDelta(100_000_000, (float) $spk->original_value, 0.01,
            'original_value means "what this SPK started at", not "value before the last addendum"');
    }

    // ------------------------------------------------------------ shape guards

    public function test_an_addendum_against_a_draft_spk_is_refused(): void
    {
        $spk = $this->makeSubcontract(['value' => 100_000_000.0]);

        try {
            $this->draftAddendum($spk, 10_000_000);
            $this->fail('a draft SPK is edited, not amended');
        } catch (LogicException $e) {
            $this->assertStringContainsString('ubah nilainya langsung selama masih draf', $e->getMessage());
        }
    }

    public function test_added_scope_without_lines_is_refused(): void
    {
        [$spk] = $this->approvedSpk();

        try {
            $this->draftAddendum($spk, 30_000_000, ['items' => []]);
            $this->fail('a plafon raised into space no opname can reach is a trap, not a feature');
        } catch (LogicException $e) {
            $this->assertStringContainsString('harus membawa baris pekerjaan baru', $e->getMessage());
        }
    }

    public function test_removed_scope_with_lines_is_refused(): void
    {
        [$spk] = $this->approvedSpk();

        try {
            $this->draftAddendum($spk, -30_000_000, ['items' => [[
                'description' => 'Baris yang tidak seharusnya ada',
                'qty' => 1,
                'unit_price' => 30_000_000,
            ]]]);
            $this->fail('pekerjaan kurang carries no lines');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Pekerjaan kurang tidak membawa baris', $e->getMessage());
        }
    }

    public function test_lines_that_do_not_sum_to_the_change_are_refused(): void
    {
        [$spk] = $this->approvedSpk();

        try {
            $this->draftAddendum($spk, 30_000_000, ['items' => [[
                'description' => 'Pekerjaan tambahan',
                'qty' => 1,
                'unit_price' => 20_000_000, // ≠ 30 juta
            ]]]);
            $this->fail('the plafon and the claimable lines must move by the same number');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tidak sama dengan perubahan nilai', $e->getMessage());
        }
    }

    // --------------------------------------------------------- director gate

    /**
     * The evasion this closes: SPK Rp 150 juta approved without a director
     * (below the Rp 200 juta threshold), then a Rp 100 juta addendum — a
     * Rp 250 juta commitment. Without the stamp-and-check pair, no director
     * would ever see it.
     */
    public function test_an_addendum_crossing_the_threshold_needs_a_director(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$spk] = $this->approvedSpk(150_000_000.0);
        $addendum = $this->draftAddendum($spk, 100_000_000);
        $addendum->submit($this->actor());

        $this->assertTrue((bool) $addendum->refresh()->needs_director_approval, 'stamped on submit');

        $plainApprover = User::query()->create([
            'name' => 'Manajer Biasa',
            'email' => 'manajer-biasa@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $plainApprover->givePermissionTo('scm.approve');

        try {
            $this->service->approve($addendum, $plainApprover);
            $this->fail('a non-director may not approve an addendum flagged for one');
        } catch (LogicException $e) {
            $this->assertStringContainsString('scm.approve-director', $e->getMessage());
        }

        $this->assertEqualsWithDelta(150_000_000, (float) $spk->refresh()->value, 0.01, 'the refusal moved nothing');

        $director = User::query()->create([
            'name' => 'Direktur',
            'email' => 'direktur@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $director->givePermissionTo('scm.approve', 'scm.approve-director');

        $this->service->approve($addendum->refresh(), $director);

        $this->assertEqualsWithDelta(250_000_000, (float) $spk->refresh()->value, 0.01);
    }

    public function test_below_the_threshold_no_director_is_demanded(): void
    {
        [$spk] = $this->approvedSpk(100_000_000.0);
        $addendum = $this->draftAddendum($spk, 20_000_000);
        $addendum->submit($this->actor());

        $this->assertFalse((bool) $addendum->refresh()->needs_director_approval);

        $this->service->approve($addendum, $this->approver());

        $this->assertEqualsWithDelta(120_000_000, (float) $spk->refresh()->value, 0.01);
    }

    // ------------------------------------------------------------ HTTP wiring

    /**
     * The endpoints exist and carry the scm permissions — an addendum the SPA
     * cannot reach is not an addendum.
     */
    public function test_the_addendum_endpoints_are_wired(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$spk] = $this->approvedSpk();

        $maker = User::query()->create([
            'name' => 'Staf Subkon',
            'email' => 'staf-subkon@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $maker->givePermissionTo('scm.create', 'scm.update');

        Sanctum::actingAs($maker);

        $created = $this->postJson('/api/subcontract/addenda', [
            'subcontract_id' => $spk->id,
            'addendum_date' => '2026-06-01',
            'title' => 'Tambah pekerjaan ME lantai 2',
            'change_type' => 'eskalasi_harga',
            'value_change' => 25_000_000,
            'items' => [[
                'description' => 'Eskalasi harga besi beton',
                'qty' => 1,
                'unit' => 'ls',
                'unit_price' => 25_000_000,
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('eskalasi_harga', $created['change_type'], 'change_type ships on day one');

        $this->postJson("/api/subcontract/addenda/{$created['id']}/submit")->assertOk();

        $checker = User::query()->create([
            'name' => 'Manajer Subkon',
            'email' => 'manajer-subkon@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $checker->givePermissionTo('scm.approve');
        Sanctum::actingAs($checker);

        $this->postJson("/api/subcontract/addenda/{$created['id']}/approve")->assertOk();

        $this->assertEqualsWithDelta(125_000_000, (float) $spk->refresh()->value, 0.01);

        // Riwayat untuk layar SPK: nilai asal dan nilai kini berdampingan.
        $summary = $this->getJson("/api/subcontract/subcontracts/{$spk->id}/addendum-summary")
            ->assertOk()->json('data');

        $this->assertEqualsWithDelta(100_000_000, $summary['original_value'], 0.01);
        $this->assertEqualsWithDelta(125_000_000, $summary['current_value'], 0.01);
        $this->assertSame(1, $summary['addendum_count']);
    }
}
