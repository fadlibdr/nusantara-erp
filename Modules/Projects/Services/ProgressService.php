<?php

namespace Modules\Projects\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;
use Modules\Projects\Models\WeeklyProgress;

class ProgressService
{
    /**
     * Record progress on a LEAF task, then roll it up: every ancestor gets the
     * weighted average of its children, and the project actual becomes the
     * weighted sum of all leaf tasks.
     */
    public function updateTaskProgress(
        WbsTask $task,
        float $progressPct,
        ?string $actualStart = null,
        ?string $actualEnd = null,
    ): WbsTask {
        if ($task->children()->exists()) {
            throw new LogicException(
                "Task {$task->wbs_code} has children; progress is entered on leaf tasks and rolls up automatically."
            );
        }

        // Site data entry stops when the project does. Progress written into a
        // closed project silently moves actual_progress_pct — the number the
        // BAST II checklist and the EVM report both read as history.
        $task->project()->firstOrFail()->assertOperational('progres paket pekerjaan');

        $progressPct = round(max(0.0, min(100.0, $progressPct)), 4);

        return DB::transaction(function () use ($task, $progressPct, $actualStart, $actualEnd): WbsTask {
            $task->forceFill([
                'progress_pct' => $progressPct,
                'actual_start' => $actualStart
                    ?? $task->actual_start?->toDateString()
                    ?? ($progressPct > 0 ? now()->toDateString() : null),
                'actual_end' => $actualEnd
                    ?? ($progressPct >= 100
                        ? ($task->actual_end?->toDateString() ?? now()->toDateString())
                        : $task->actual_end?->toDateString()),
            ])->save();

            $this->rollUpAncestors($task);
            $this->recalcProjectProgress($task->project()->firstOrFail());

            return $task->refresh();
        });
    }

    /**
     * Project actual progress = sum over leaf tasks of (progress * weight / 100).
     * Leaf weights sum to 100 per project, so the result is already a percentage.
     */
    public function recalcProjectProgress(Project $project): Project
    {
        $leaves = $project->wbsTasks()->whereDoesntHave('children')->get();

        $actual = 0.0;

        foreach ($leaves as $leaf) {
            $actual += (float) $leaf->progress_pct * (float) $leaf->weight_pct / 100;
        }

        $project->forceFill(['actual_progress_pct' => round($actual, 4)])->save();

        return $project;
    }

    /**
     * Recompute every parent task from its children (depth-first) and then the
     * project actual. Used after bulk edits and by the seeder.
     */
    public function recalcWbsRollups(Project $project): Project
    {
        $tasks = $project->wbsTasks()->get();
        $roots = $tasks->filter(fn (WbsTask $task): bool => $task->parent_id === null);

        foreach ($roots as $root) {
            $this->computeSubtreeProgress($root, $tasks);
        }

        return $this->recalcProjectProgress($project);
    }

    /**
     * Kurva-S data points, one per reported week (cumulative percentages).
     */
    public function sCurveData(Project $project): array
    {
        $weeks = $project->weeklyProgress()->orderBy('week_no')->get()
            ->map(fn (WeeklyProgress $week): array => [
                'week_no' => (int) $week->week_no,
                'period_start' => $week->period_start?->toDateString(),
                'period_end' => $week->period_end?->toDateString(),
                'planned_pct' => (float) $week->planned_pct,
                'actual_pct' => (float) $week->actual_pct,
                'deviation_pct' => (float) $week->deviation_pct,
            ])
            ->all();

        return [
            'project_id' => $project->id,
            'project_code' => $project->code,
            'planned_progress_pct' => (float) $project->planned_progress_pct,
            'actual_progress_pct' => (float) $project->actual_progress_pct,
            'weeks' => $weeks,
        ];
    }

    /**
     * Upsert one weekly progress row (unique per project + week_no).
     * Deviation is derived (actual - planned); the project header planned
     * percentage follows the latest reported week.
     */
    public function recordWeekly(array $data): WeeklyProgress
    {
        // Same guard as updateTaskProgress: a kurva-S point on a closed project
        // rewrites the header planned percentage below, which is the "plan" every
        // deviation report compares against.
        Project::query()->findOrFail((int) $data['project_id'])
            ->assertOperational('progres mingguan');

        return DB::transaction(function () use ($data): WeeklyProgress {
            $planned = round((float) $data['planned_pct'], 4);
            $actual = round((float) $data['actual_pct'], 4);

            $row = WeeklyProgress::query()->updateOrCreate(
                [
                    'project_id' => (int) $data['project_id'],
                    'week_no' => (int) $data['week_no'],
                ],
                [
                    'period_start' => $data['period_start'],
                    'period_end' => $data['period_end'],
                    'planned_pct' => $planned,
                    'actual_pct' => $actual,
                    'deviation_pct' => round($actual - $planned, 4),
                    'notes' => $data['notes'] ?? null,
                ],
            );

            $project = Project::query()->findOrFail((int) $data['project_id']);
            // reorder() is mandatory: weeklyProgress() carries a default
            // ->orderBy('week_no') asc, and a plain orderByDesc() would only be
            // APPENDED to it ("order by week_no asc, week_no desc"), so first()
            // would return the earliest week instead of the latest one.
            $latest = $project->weeklyProgress()->reorder()->orderByDesc('week_no')->first();

            if ($latest && $latest->id === $row->id) {
                $project->forceFill(['planned_progress_pct' => $planned])->save();
            }

            return $row;
        });
    }

    /**
     * Weighted subtree progress: leaves return their own progress, parents get
     * sum(child progress * child weight) / sum(child weight) and are persisted.
     *
     * @param  Collection<int, WbsTask>  $tasks  all tasks of the project
     */
    private function computeSubtreeProgress(WbsTask $task, Collection $tasks): float
    {
        $children = $tasks->filter(fn (WbsTask $candidate): bool => $candidate->parent_id === $task->id);

        if ($children->isEmpty()) {
            return (float) $task->progress_pct;
        }

        $weightSum = 0.0;
        $weighted = 0.0;

        foreach ($children as $child) {
            $childProgress = $this->computeSubtreeProgress($child, $tasks);
            $weightSum += (float) $child->weight_pct;
            $weighted += $childProgress * (float) $child->weight_pct;
        }

        $progress = $weightSum > 0 ? round($weighted / $weightSum, 4) : 0.0;

        $task->forceFill(['progress_pct' => $progress])->save();

        return $progress;
    }

    /**
     * Walk up the parent chain recomputing each ancestor from its direct children.
     */
    private function rollUpAncestors(WbsTask $task): void
    {
        $parent = $task->parent_id ? WbsTask::query()->find($task->parent_id) : null;

        while ($parent) {
            $children = $parent->children()->get();
            $weightSum = (float) $children->sum('weight_pct');

            $progress = 0.0;

            if ($weightSum > 0) {
                foreach ($children as $child) {
                    $progress += (float) $child->progress_pct * (float) $child->weight_pct;
                }
                $progress = round($progress / $weightSum, 4);
            } else {
                $progress = round((float) $children->avg('progress_pct'), 4);
            }

            $parent->forceFill(['progress_pct' => $progress])->save();

            $parent = $parent->parent_id ? WbsTask::query()->find($parent->parent_id) : null;
        }
    }
}
