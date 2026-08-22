<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\DocumentStatus;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Projects\Services\BaselineService;

/**
 * Freeze revision 0 of PRJ-2026-001 on the live demo dataset.
 *
 * Without it, erp1.pi2.co.id ships the EVM screen with an empty state on every
 * project and nobody can see that the demo data actually says SPI 0,8913 and
 * CPI 101,6283 — the two numbers the whole feature exists to surface, and the
 * second of which is the reason the report has to be able to say "do not trust
 * me". A data migration is the only sanctioned way to touch
 * database/database.sqlite (seeders may not be re-run against it), following
 * the in-repo precedent of 2026_07_25_001195/001196 and 2026_07_25_000496.
 *
 * PRJ-2026-002 is deliberately NOT baselined. It has no RAP at all, so the live
 * demo shows both paths side by side: a working EVM screen on one project and
 * the named "susun RAP lebih dulu" empty state on the other.
 *
 * The baseline is written approved DIRECTLY rather than through submit() +
 * approve(). Maker-checker needs two people and this migration is nobody; the
 * runtime path is exercised by tests/Feature/Projects/ProjectBaselineTest.
 */
return new class extends Migration
{
    private const PROJECT_CODE = 'PRJ-2026-001';

    private const EFFECTIVE_DATE = '2026-02-02';

    public function up(): void
    {
        if (! Schema::hasTable('prj_baselines') || ! Schema::hasTable('prj_projects')) {
            return;
        }

        $project = Project::query()->where('code', self::PROJECT_CODE)->first();

        if ($project === null) {
            return; // fresh install ordering — the seeder covers this case
        }

        // Idempotent: a project that already has a baseline keeps it. Running
        // this twice must never produce a revision 1 nobody asked for.
        if (ProjectBaseline::query()->where('project_id', $project->id)->exists()) {
            return;
        }

        $direktur = DB::table('users')->where('email', 'direktur@nusantara.test')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');

        if ($direktur === null) {
            return;
        }

        try {
            $baseline = app(BaselineService::class)->snapshot($project, [
                'effective_date' => self::EFFECTIVE_DATE,
                'notes' => 'Baseline awal saat penandatanganan kontrak CTR/2026/I/0001.',
            ]);
        } catch (LogicException) {
            // No RAP, no WBS, or leaf weights that do not close on 100%. The
            // demo file satisfies all three, but a partially seeded database
            // must not fail a deploy over a chart.
            return;
        }

        $baseline->forceFill([
            'status' => DocumentStatus::Approved,
            'created_by' => (int) $direktur,
            'approved_by' => (int) $direktur,
            'approved_at' => now(),
        ])->save();

        // One approval row so the trail is not empty when somebody opens the
        // baseline looking for who agreed to it.
        $baseline->approvals()->create([
            'action' => 'approved',
            'user_id' => (int) $direktur,
            'note' => 'Baseline awal proyek — dibekukan bersamaan dengan penandatanganan kontrak.',
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('prj_baselines')) {
            return;
        }

        $projectId = DB::table('prj_projects')->where('code', self::PROJECT_CODE)->value('id');

        if ($projectId === null) {
            return;
        }

        // Only revision 0, and only the one this migration created. A later
        // re-baseline is somebody's decision and is not this migration's to undo.
        ProjectBaseline::query()
            ->where('project_id', $projectId)
            ->where('revision_no', 0)
            ->get()
            ->each(function (ProjectBaseline $baseline): void {
                $baseline->tasks()->delete();
                $baseline->points()->delete();
                $baseline->approvals()->delete();
                $baseline->delete();
            });
    }
};
