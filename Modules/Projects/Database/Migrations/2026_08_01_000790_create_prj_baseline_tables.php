<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baseline proyek — the frozen plan every EVM number is measured against.
 *
 * Without a frozen plan there is no earned value, only opinion. PRJ-2026-001
 * shows why: prj_projects.planned_progress_pct reads 2,0000 while its own
 * latest weekly row (minggu 8, 29-03-2026) reads 62,0000, and both are
 * rewritable — ProgressService::recordWeekly does an updateOrCreate. A claim
 * for extension of time built on a number anybody can overwrite proves nothing
 * to an arbitrator. These three tables hold a plan that CANNOT move: the BAC
 * and which RAP produced it, the contract value at that instant, every WBS row
 * with its weight and its dates, and the monthly curve derived from them.
 *
 * NO softDeletes ANYWHERE HERE, deliberately. Soft-deleting an approved
 * baseline is exactly the quiet rewrite this feature exists to prevent — the
 * row disappears from every query, the "current" baseline silently becomes the
 * previous one, and the deviation report starts comparing against a plan
 * nobody agreed to. Approved baselines are undeletable (BaselineService
 * refuses) and drafts are hard-deleted, so what survives is always what was
 * agreed.
 *
 * Numbering: the Projects block 000700–000799 had 000700..000780 taken, so all
 * three tables ship in ONE migration at 000790 — the same choice Finance made
 * with 2026_07_28_001101_create_fin_revenue_recognition_tables.php — which
 * keeps the increment-by-10 rule intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_baselines', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('project_id')->constrained('prj_projects')->cascadeOnDelete();

            // Revision 0 is the original baseline. Later revisions are the
            // re-baselines, and the chain from 0 forward is append-only.
            $table->unsignedInteger('revision_no')->default(0);
            $table->string('status', 30)->default('draft'); // DocumentStatus
            // Agreed at contract signature, entered afterwards — which is why
            // this is stored separately from approved_at rather than derived
            // from it. PRJ-2026-001's baseline is effective 02-02-2026 and was
            // entered months later.
            $table->date('effective_date');
            $table->text('reason')->nullable();
            // The document that authorised a re-baseline: a CCO code, an
            // "Addendum I", an approved EOT. Free text on purpose — the
            // authority for a re-baseline is not always a row in this system.
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_no', 60)->nullable();

            // BAC — budget at completion. Frozen in rupiah, together with WHICH
            // RAP produced it and what that RAP's status was at that instant,
            // because RAP/2026/0001 is 'submitted' and may still move.
            $table->decimal('bac', 18, 2);
            $table->string('bac_source', 20); // BacSource
            // Cross-module reference (Estimation) — indexed, no DB constraint.
            $table->unsignedBigInteger('cost_budget_id')->nullable();
            $table->string('cost_budget_code', 40)->nullable();
            $table->string('cost_budget_status', 30)->nullable();

            // Cross-module reference (Crm) — indexed, no DB constraint.
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('contract_code', 40)->nullable();
            $table->decimal('contract_value', 18, 2)->default(0);

            // planned_finish is the WBS's own last planned_end; contract_finish
            // is prj_projects.end_date. They are recorded separately because on
            // PRJ-2026-001 the WBS ends 30-06-2027 while the contract ends
            // 31-07-2027, and an extension-of-time argument turns on exactly
            // that kind of month-wide gap.
            $table->date('planned_start');
            $table->date('planned_finish');
            $table->date('contract_finish')->nullable();
            $table->unsignedInteger('planned_duration_days');

            $table->string('curve_source', 20)->default('wbs');
            $table->unsignedInteger('leaf_task_count');
            $table->decimal('leaf_weight_total', 8, 4);
            $table->text('notes')->nullable();

            // User semantics (users.id) — app-owned, no DB constraint.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            // Superseding writes ONLY these two columns onto the predecessor.
            // Not one byte of its content is touched, so revision 0 stays
            // readable in full however many re-baselines follow it.
            $table->dateTime('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->timestamps();

            // lockForUpdate() is a silent no-op on SQLite, so "one revision N
            // per project" cannot rest on it. THIS is the guarantee: two
            // concurrent snapshots cannot both claim revision N — the loser
            // gets a constraint violation the service turns into "muat ulang".
            $table->unique(['project_id', 'revision_no']);
            $table->index(['project_id', 'status']);
            $table->index('cost_budget_id');
            $table->index('contract_id');
            $table->index('superseded_by_id');
        });

        Schema::create('prj_baseline_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baseline_id')->constrained('prj_baselines')->cascadeOnDelete();
            // NO foreign key, even though prj_wbs_tasks is in this same module
            // and the FK rule would normally apply. The frozen row has to
            // survive the live task being deleted: a scope item dropped after
            // the baseline was agreed is precisely what the deviation report
            // exists to show, and a cascade (or a null-on-delete that took the
            // weight with it) would erase the evidence at the moment it starts
            // to matter.
            $table->unsignedBigInteger('wbs_task_id')->nullable();
            $table->string('wbs_code', 20);
            $table->string('parent_wbs_code', 20)->nullable();
            $table->string('name', 500);
            $table->boolean('is_leaf')->default(true);
            $table->decimal('weight_pct', 8, 4);
            $table->date('planned_start');
            $table->date('planned_end');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('wbs_task_id');
            $table->index(['baseline_id', 'wbs_code']);
        });

        Schema::create('prj_baseline_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baseline_id')->constrained('prj_baselines')->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->date('period_end');
            // PRECOMPUTED SAMPLES FOR CHARTING, NOT THE AUTHORITY. Planned value
            // at an arbitrary as_of is always computed exactly from the frozen
            // TASK windows by Modules\Projects\Support\PlannedCurve, never by
            // interpolating between these monthly points — interpolating a curve
            // whose slope changes whenever a work package starts or ends is off
            // by a few tenths of a percent, and a schedule variance argued to
            // two decimals cannot rest on a number that disagrees with itself.
            $table->decimal('planned_pct', 8, 4);
            $table->decimal('planned_value', 18, 2);
            $table->timestamps();

            $table->unique(['baseline_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_baseline_points');
        Schema::dropIfExists('prj_baseline_tasks');
        Schema::dropIfExists('prj_baselines');
    }
};
