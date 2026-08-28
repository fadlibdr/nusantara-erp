<?php

namespace Modules\Quality\Services;

use Illuminate\Validation\ValidationException;
use Modules\Quality\Models\ConcreteTest;

/**
 * P1-QC — THE HONESTY CORE: pass/fail on a signed concrete test sheet is
 * arithmetic against a published standard, never an opinion. Every number below
 * is sourced; none is invented.
 *
 * ---------------------------------------------------------------- the relation
 *
 * A grade is stated one of two ways in Indonesian practice:
 *
 *   K-xxx   the characteristic compressive strength (kekuatan tekan
 *           karakteristik) of a 15 cm CUBE, in kg/cm² — the PBI 1971 (N.I.-2)
 *           convention still written on most ready-mix dockets.
 *   fc'-xx  the specified compressive strength of a CYLINDER, in MPa — the
 *           SNI 2847:2019 (adopting ACI 318) convention the code now uses.
 *
 * SNI 2847:2019 defines acceptance on fc' (cylinder, MPa), and SNI 1974:2011 is
 * the cylinder compression test method, so the recorded `strength_mpa` is read
 * as a cylinder fc' in MPa and a K-grade target is converted to its cylinder
 * equivalent before the comparison:
 *
 *   1) kg/cm² → MPa:  × 0.0980665   (1 kgf/cm² = 9.80665 N / 1e-4 m² = 0.0980665 MPa)
 *   2) cube → cylinder: × 0.83       (fc' silinder ≈ 0.83 × σbk kubus — the PBI
 *                                     cube-to-cylinder conversion in common use)
 *
 *   K-350 → 350 × 0.0980665 = 34.32 MPa (kubus) → × 0.83 = 28.49 MPa (fc' silinder)
 *
 * ------------------------------------------------------------- age adjustment
 *
 * Acceptance is on the 28-day fc'. A specimen broken earlier is compared against
 * the age-adjusted fraction of that target, using the maturity ratios of
 * PBI 1971 (N.I.-2) Tabel 4.1.4 for ordinary Portland cement (semen tipe I):
 *
 *   umur (hari):  7      14     28
 *   perbandingan: 0.65   0.88   1.00
 *
 * A 7-day break passing 0.65 of target is "on track"; the 28-day break is the
 * acceptance test. An age this table does not list is refused rather than
 * guessed — inventing a ratio would be exactly the opinion this service exists
 * to keep off the sheet.
 */
class ConcreteStrengthService
{
    /** 1 kgf/cm² in MPa. */
    private const KGFCM2_TO_MPA = 0.0980665;

    /** fc' cylinder ≈ 0.83 × σbk cube (PBI cube-to-cylinder conversion). */
    private const CUBE_TO_CYLINDER = 0.83;

    /** PBI 1971 N.I.-2 Tabel 4.1.4, semen tipe I — strength as a fraction of the 28-day value. */
    private const AGE_RATIO = [
        7 => 0.65,
        14 => 0.88,
        28 => 1.00,
    ];

    /**
     * The specified 28-day cylinder strength fc' (MPa) a grade string means.
     *
     * @throws ValidationException on a grade string that names no standard grade
     */
    public function targetFcMpa(string $grade): float
    {
        $normalised = strtoupper(str_replace([' ', "'", '`', '’'], '', trim($grade)));

        // K-xxx (or Kxxx): characteristic cube strength in kg/cm².
        if (preg_match('/^K-?(\d+(?:\.\d+)?)$/', $normalised, $m) === 1) {
            return round(((float) $m[1]) * self::KGFCM2_TO_MPA * self::CUBE_TO_CYLINDER, 2);
        }

        // FC-xx / FCxx: cylinder fc' already in MPa.
        if (preg_match('/^FC-?(\d+(?:\.\d+)?)$/', $normalised, $m) === 1) {
            return round((float) $m[1], 2);
        }

        throw ValidationException::withMessages(['grade' => sprintf(
            'Mutu beton "%s" tidak dikenali; gunakan K-xxx (kubus, kg/cm²) atau fc\'-xx (silinder, MPa).',
            $grade,
        )]);
    }

    /**
     * The target this age is judged against: the 28-day fc' scaled by the
     * maturity ratio for the age.
     *
     * @throws ValidationException on an age the maturity table does not carry
     */
    public function expectedAtAge(float $targetFcMpa, int $ageDays): float
    {
        if (! array_key_exists($ageDays, self::AGE_RATIO)) {
            throw ValidationException::withMessages(['age_days' => sprintf(
                'Umur uji %d hari tidak ada pada tabel kematangan PBI 1971 (7, 14, atau 28 hari); '
                    .'pass/fail hanya dihitung pada umur baku, bukan ditebak.',
                $ageDays,
            )]);
        }

        return round($targetFcMpa * self::AGE_RATIO[$ageDays], 2);
    }

    /** Whether one measured strength clears its age-adjusted target for the grade. */
    public function passes(string $grade, int $ageDays, float $strengthMpa): bool
    {
        return round($strengthMpa, 2) >= $this->expectedAtAge($this->targetFcMpa($grade), $ageDays);
    }

    /**
     * The computed verdict for a test row against its sample's grade — the value
     * stored on qc_concrete_tests.pass. Loads the sample when the caller has not.
     */
    public function evaluate(ConcreteTest $test): bool
    {
        $sample = $test->relationLoaded('sample') ? $test->sample : $test->sample()->first();

        return $this->passes((string) $sample->grade, (int) $test->age_days, (float) $test->strength_mpa);
    }
}
