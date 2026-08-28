<?php

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Quality\Services\ConcreteStrengthService;

class ConcreteSampleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $strength = app(ConcreteStrengthService::class);
        $target = null;

        // The specified 28-day fc' the grade means — the number the pass/fail is
        // measured against, shown so the reader is never left guessing the target.
        try {
            $target = $strength->targetFcMpa((string) $this->grade);
        } catch (\Throwable) {
            $target = null;
        }

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'location_id' => $this->location_id,
            'location_path' => $this->whenLoaded('location', fn () => $this->location?->path()),
            'pour_date' => $this->pour_date?->toDateString(),
            'grade' => $this->grade,
            'target_fc_mpa' => $target,
            'slump_cm' => $this->slump_cm,
            'truck_no' => $this->truck_no,
            'volume_m3' => $this->volume_m3,
            'sample_count' => (int) $this->sample_count,
            'tests' => $this->whenLoaded('tests', fn () => $this->tests->map(fn ($test) => [
                'id' => $test->id,
                'age_days' => (int) $test->age_days,
                'strength_mpa' => $test->strength_mpa,
                'target_at_age_mpa' => $target === null ? null : $strength->expectedAtAge($target, (int) $test->age_days),
                'lab' => $test->lab,
                'tested_at' => $test->tested_at?->toDateString(),
                'pass' => (bool) $test->pass,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
