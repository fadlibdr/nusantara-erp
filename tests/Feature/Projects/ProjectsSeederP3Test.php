<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Database\Seeders\CrmDatabaseSeeder;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Estimation\Database\Seeders\EstimationDatabaseSeeder;
use Modules\Iam\Database\Seeders\IamDatabaseSeeder;
use Modules\Projects\Database\Seeders\ProjectsDatabaseSeeder;
use Modules\Projects\Enums\ZoneCertificateStatus;
use Modules\Projects\Models\ContractVariation;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WeeklyProgress;
use Modules\Projects\Models\ZoneCertificate;
use Modules\Projects\Services\ZoneCertificateService;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * THE DEMO DATABASE MUST CONTAIN THE THINGS P3 IS JUDGED ON.
 *
 * Roadmap §3 asks each package for "a seeder producing a believable demo
 * dataset that exercises the module (documents in several statuses)", and P3's
 * acceptance criteria turn on two tables — prj_zone_certificates (BAPP) and
 * prj_contract_variations (the volume half of the opname ceiling) — plus one
 * transition: an APPROVED opname taking over prj_weekly_progress.actual_pct.
 * A demo in which those tables are empty and the only opname is a draft
 * demonstrates none of it.
 *
 * The upstream canon is seeded with the REAL seeders in the real order (Iam →
 * Crm → Estimation → Projects, database/seeders/DatabaseSeeder::$moduleOrder),
 * because the thing under test is the demo database a `migrate:fresh --seed`
 * actually produces — not a hand-built approximation of it.
 */
class ProjectsSeederP3Test extends ErpTestCase
{
    private function seedCanon(): void
    {
        $this->seed(IamDatabaseSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CrmDatabaseSeeder::class);
        $this->seed(EstimationDatabaseSeeder::class);
        $this->seed(ProjectsDatabaseSeeder::class);
    }

    private function graha(): Project
    {
        return Project::query()->where('code', 'PRJ-2026-001')->firstOrFail();
    }

    /**
     * KRITERIA #6 IS DEMONSTRABLE: a BAPP in every status that means something,
     * and a zone whose CURRENT status is the one that stops an owner claim.
     */
    public function test_it_seeds_a_bapp_in_each_meaningful_status_including_a_blocked_zone(): void
    {
        $this->seedCanon();

        $statuses = ZoneCertificate::query()->pluck('status')
            ->map(fn (ZoneCertificateStatus $status): string => $status->value)
            ->unique()->sort()->values()->all();

        $this->assertSame(['check', 'done', 'waiting_repair'], $statuses);

        // "Latest wins" is the rule the gate and the claim both apply, so the
        // demo has to contain a zone whose latest sheet is each of the two
        // outcomes — one repaired and accepted, one still waiting.
        $service = app(ZoneCertificateService::class);
        $graha = $this->graha();

        $current = ZoneCertificate::query()
            ->where('project_id', $graha->id)
            ->pluck('location_id')->unique()
            ->mapWithKeys(fn (int $id): array => [$id => $service->statusFor($graha->id, $id)?->value])
            ->all();

        $this->assertContains('done', $current, 'No zone in the demo is finished.');
        $this->assertContains(
            'waiting_repair',
            $current,
            'No zone the owner claim would refuse: kriteria #6 cannot be demonstrated on this demo.',
        );
    }

    /** The volume half of the ceiling, against a CCO that is actually signed. */
    public function test_it_seeds_a_contract_variation_against_an_approved_change_order(): void
    {
        $this->seedCanon();

        $variation = ContractVariation::query()->first();
        $this->assertNotNull($variation, 'prj_contract_variations is empty in the demo database.');

        $order = ContractChangeOrder::query()->find($variation->change_order_id);
        $this->assertNotNull($order);
        $this->assertSame(DocumentStatus::Approved, $order->status);
        $this->assertSame((int) $order->contract_id, (int) $variation->contract_id);
        $this->assertNotSame(0.0, round((float) $variation->qty_change, 3));

        // THE SEED CANON SURVIVES THE ADDENDA. CONVENTIONS §8 pins this
        // contract at Rp 48,5 M and BOQ/2026/0001's grand total is the same
        // number; the seeded tambah and kurang are equal and opposite exactly
        // so that stays true, and both the contract and the project's copy of
        // its value say so.
        $contract = Contract::query()->where('code', 'CTR/2026/I/0001')->firstOrFail();
        $this->assertSame('48500000000.00', (string) $contract->value);
        $this->assertSame('48500000000.00', (string) $this->graha()->contract_value);
        $this->assertSame(
            0.0,
            round((float) ContractChangeOrder::query()->where('contract_id', $contract->id)->sum('value_change'), 2),
        );

        // The ceiling story the register exists for: the demo's draft opname
        // measures MORE galian than the BOQ sold, and only the approved
        // addendum volume makes that legal.
        $line = DB::table('prj_progress_measurement_items as items')
            ->join('prj_progress_measurements as opn', 'opn.id', '=', 'items.progress_measurement_id')
            ->where('items.boq_item_id', $variation->boq_item_id)
            ->where('opn.status', DocumentStatus::Draft->value)
            ->first(['items.qty_cum']);

        $boqQty = DB::table('est_boq_items')->where('id', $variation->boq_item_id)->value('qty');

        $this->assertNotNull($line, 'No opname measures the addendum volume, so the ceiling is never exercised.');
        $this->assertGreaterThan((float) $boqQty, (float) $line->qty_cum);
        $this->assertSame(
            round((float) $boqQty + (float) $variation->qty_change, 3),
            round((float) $line->qty_cum, 3),
            'The demo line should sit exactly on the plafon kontrak + CCO.',
        );
    }

    /**
     * The switch the whole package turns on: an APPROVED opname, and a weekly
     * row that says its percentage came from it.
     */
    public function test_it_takes_one_opname_through_to_approved_and_the_weekly_row_says_so(): void
    {
        $this->seedCanon();

        $approved = ProgressMeasurement::query()
            ->where('status', DocumentStatus::Approved->value)
            ->orderBy('id')
            ->first();

        $this->assertNotNull($approved, 'The demo never shows an approved opname.');

        $measured = WeeklyProgress::query()
            ->where('project_id', $this->graha()->id)
            ->where('actual_pct_source', WeeklyProgress::SOURCE_MEASUREMENT)
            ->get();

        $this->assertNotEmpty(
            $measured,
            'No weekly row is driven by the opname, so the actual_pct switch is invisible in the demo.',
        );

        // AND THE MEASUREMENT CONFIRMS THE DEMO'S OWN KURVA-S RATHER THAN
        // CONTRADICTING IT. Each BOQ item is measured at the physical
        // percentage its WBS leaf reports, and the leaf weights are the BOQ
        // cost shares, so the value-weighted figure lands on the 55 % the
        // seeded week 8 already carried. A seeded opname that moved that
        // number would leave the demo arguing with itself.
        foreach ($measured as $week) {
            $this->assertEqualsWithDelta(55.0, (float) $week->actual_pct, 0.01);
        }

        // ...and the weeks no opname covers are untouched, source and all: the
        // demo must show BOTH halves of the rule.
        $this->assertNotEmpty(WeeklyProgress::query()
            ->where('project_id', $this->graha()->id)
            ->where('actual_pct_source', WeeklyProgress::SOURCE_WEEKLY)
            ->get());
    }

    /**
     * FORWARD-ONLY (roadmap §7): seeding an approval may not post accounting
     * facts. Finance seeds after Projects; a journal existing at this point
     * could only have come from the approvals seeded here.
     */
    public function test_the_seeded_approvals_post_no_journal(): void
    {
        $this->seedCanon();

        if (Schema::hasTable('fin_journals')) {
            $this->assertSame(0, DB::table('fin_journals')->count());
        }

        $this->assertSame(0, DB::table('fin_ar_invoices')->count());
    }

    /**
     * Re-running the seeder converges (CONVENTIONS §8) — and the replay is the
     * WHOLE upstream chain, not just this module.
     *
     * That is not thoroughness for its own sake: EstimationDatabaseSeeder
     * rebuilds BOQ/2026/0001 through replaceSections, which hard-deletes and
     * re-inserts every est_boq_items row, so a second pass hands this seeder
     * DIFFERENT item ids for the same BOQ lines. A register keyed on the id it
     * saw last time would leave an orphan row behind and add a second one next
     * to it, and the demo's plafon screen would grow a row per `db:seed`.
     */
    public function test_it_is_idempotent_even_when_the_boq_rows_are_rebuilt(): void
    {
        $this->seedCanon();

        $before = [
            ZoneCertificate::query()->count(),
            ContractVariation::query()->count(),
            ProgressMeasurement::query()->count(),
            ContractChangeOrder::query()->count(),
            WeeklyProgress::query()->count(),
        ];

        $this->seedCanon(); // the whole chain again, exactly as `db:seed` replays it

        $this->assertSame($before, [
            ZoneCertificate::query()->count(),
            ContractVariation::query()->count(),
            ProgressMeasurement::query()->count(),
            ContractChangeOrder::query()->count(),
            WeeklyProgress::query()->count(),
        ]);

        // ...and the register still points at BOQ rows that exist.
        foreach (ContractVariation::query()->get() as $variation) {
            $this->assertNotNull(
                DB::table('est_boq_items')->where('id', $variation->boq_item_id)->value('id'),
                'A seeded variation points at a BOQ item the Estimation replay deleted.',
            );
        }
    }
}
