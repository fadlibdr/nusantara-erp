<?php

namespace Modules\Quality\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Quality\Models\ConcreteSample;
use Modules\Quality\Models\ConcreteTest;

/**
 * P1-QC: concrete-sample custody and its tests. The service owns the one thing
 * that must never be typed — `pass`, computed by ConcreteStrengthService against
 * the sample's grade. A grade the parser refuses (bad K/fc' string) or an age
 * off the maturity table refuses the whole write, so a sample can never carry a
 * test whose verdict nobody could compute.
 */
class ConcreteSampleService
{
    public function __construct(private readonly ConcreteStrengthService $strength) {}

    public function create(array $data): ConcreteSample
    {
        // Validate the grade up front — a sample with an unparseable grade could
        // never have its tests judged, so it is refused before any row is written.
        $this->strength->targetFcMpa((string) $data['grade']);

        return DB::transaction(function () use ($data): ConcreteSample {
            /** @var ConcreteSample $sample */
            $sample = ConcreteSample::query()->create(Arr::except($data, ['tests']));

            $this->writeTests($sample, $data['tests'] ?? []);

            return $sample;
        });
    }

    public function update(ConcreteSample $sample, array $data): ConcreteSample
    {
        $this->strength->targetFcMpa((string) ($data['grade'] ?? $sample->grade));

        return DB::transaction(function () use ($sample, $data): ConcreteSample {
            $sample->fill(Arr::except($data, ['tests']))->save();

            if (array_key_exists('tests', $data)) {
                $sample->tests()->delete();
                $this->writeTests($sample, $data['tests'] ?? []);
            }

            return $sample;
        });
    }

    /** Add one test to an existing sample, its pass computed. */
    public function addTest(ConcreteSample $sample, array $data): ConcreteTest
    {
        $pass = $this->strength->passes((string) $sample->grade, (int) $data['age_days'], (float) $data['strength_mpa']);

        /** @var ConcreteTest $test */
        $test = $sample->tests()->create(Arr::only($data, ['age_days', 'strength_mpa', 'lab', 'tested_at']) + ['pass' => $pass]);

        return $test;
    }

    private function writeTests(ConcreteSample $sample, array $tests): void
    {
        foreach ($tests as $line) {
            $this->addTest($sample, $line);
        }
    }
}
