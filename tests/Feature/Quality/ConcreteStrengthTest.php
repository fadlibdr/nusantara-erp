<?php

namespace Tests\Feature\Quality;

use Illuminate\Validation\ValidationException;
use Modules\Quality\Models\ConcreteSample;
use Modules\Quality\Services\ConcreteStrengthService;
use Tests\ErpTestCase;

/**
 * P1-QC — the honesty core: pass/fail on a benda-uji sheet is arithmetic against
 * the SNI/PBI relation, never a typed opinion. K-350 (kubus, kg/cm²) → fc'
 * (silinder, MPa) is × 0.0980665 × 0.83 = 28,49 MPa, and earlier ages compare
 * against the PBI 1971 maturity fractions (7d = 0,65, 14d = 0,88, 28d = 1,00).
 */
class ConcreteStrengthTest extends ErpTestCase
{
    use QualityFixtures;

    private ConcreteStrengthService $strength;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strength = app(ConcreteStrengthService::class);
    }

    public function test_a_k_grade_converts_to_its_cylinder_fc_target(): void
    {
        // 350 kg/cm² × 0.0980665 = 34,32 MPa (kubus) × 0.83 = 28,49 MPa (fc').
        $this->assertEqualsWithDelta(28.49, $this->strength->targetFcMpa('K-350'), 0.01);
        // 300 × 0.0980665 × 0.83 = 24,42 MPa.
        $this->assertEqualsWithDelta(24.42, $this->strength->targetFcMpa('K-300'), 0.01);
    }

    public function test_an_fc_grade_is_already_the_cylinder_strength_in_mpa(): void
    {
        $this->assertEqualsWithDelta(25.0, $this->strength->targetFcMpa("fc'-25"), 0.001);
        $this->assertEqualsWithDelta(30.0, $this->strength->targetFcMpa('fc30'), 0.001);
    }

    public function test_the_age_adjusted_target_uses_the_pbi_maturity_fractions(): void
    {
        $target = $this->strength->targetFcMpa('K-350'); // 28,49

        $this->assertEqualsWithDelta(18.52, $this->strength->expectedAtAge($target, 7), 0.01);
        $this->assertEqualsWithDelta(25.07, $this->strength->expectedAtAge($target, 14), 0.01);
        $this->assertEqualsWithDelta(28.49, $this->strength->expectedAtAge($target, 28), 0.01);
    }

    /** DoD: strength pass/fail at each age. */
    public function test_pass_and_fail_at_each_age(): void
    {
        // 7-day target 18,52 MPa
        $this->assertTrue($this->strength->passes('K-350', 7, 20.0));
        $this->assertFalse($this->strength->passes('K-350', 7, 17.0));

        // 14-day target 25,07 MPa
        $this->assertTrue($this->strength->passes('K-350', 14, 26.0));
        $this->assertFalse($this->strength->passes('K-350', 14, 24.0));

        // 28-day target 28,49 MPa — the acceptance test
        $this->assertTrue($this->strength->passes('K-350', 28, 30.0));
        $this->assertFalse($this->strength->passes('K-350', 28, 27.0));
    }

    public function test_an_unreadable_grade_is_refused_not_guessed(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tidak dikenali');

        $this->strength->targetFcMpa('beton bagus');
    }

    public function test_an_age_off_the_maturity_table_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tabel kematangan');

        $this->strength->expectedAtAge(28.49, 21);
    }

    /**
     * The verdict is the SERVICE'S arithmetic, never a value the client sent:
     * even with a failing 28-day break the row stores pass=false, and the API
     * accepts no `pass` field at all.
     */
    public function test_the_api_stores_the_computed_pass_never_a_typed_one(): void
    {
        $project = $this->project();
        $location = $this->location($project);
        $this->admin();

        $response = $this->postJson('api/quality/concrete-samples', [
            'project_id' => $project->id,
            'location_id' => $location->id,
            'pour_date' => '2026-03-16',
            'grade' => 'K-350',
            'slump_cm' => 12,
            'sample_count' => 6,
            'tests' => [
                ['age_days' => 7, 'strength_mpa' => 20, 'pass' => false], // a lie the API must ignore
                ['age_days' => 28, 'strength_mpa' => 27, 'pass' => true],  // another lie
            ],
        ]);

        $response->assertCreated();

        $sample = ConcreteSample::query()->with('tests')->findOrFail($response->json('data.id'));
        $seven = $sample->tests->firstWhere('age_days', 7);
        $twentyEight = $sample->tests->firstWhere('age_days', 28);

        $this->assertTrue((bool) $seven->pass, '20 MPa clears the 7-day target 18,52');
        $this->assertFalse((bool) $twentyEight->pass, '27 MPa fails the 28-day target 28,49');
    }

    public function test_a_sample_with_an_unparseable_grade_is_refused_before_any_row_lands(): void
    {
        $project = $this->project();
        $location = $this->location($project);
        $this->admin();

        $this->postJson('api/quality/concrete-samples', [
            'project_id' => $project->id,
            'location_id' => $location->id,
            'pour_date' => '2026-03-16',
            'grade' => 'mutu tinggi',
            'sample_count' => 3,
            'tests' => [['age_days' => 28, 'strength_mpa' => 30]],
        ])->assertStatus(422);

        $this->assertSame(0, ConcreteSample::query()->count());
    }
}
