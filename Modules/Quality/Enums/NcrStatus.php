<?php

namespace Modules\Quality\Enums;

/**
 * P1-QC: the life of a Non-Conformance Report — its OWN status enum, NOT
 * DocumentStatus and NOT the Approvable cycle.
 *
 * An NCR is not submitted and approved into existence: somebody raised it
 * because work did not conform. What it needs is a correction and a verification
 * that the correction held, which is a different lifecycle with a different
 * meaning for every state — the same argument DefectStatus and IncidentStatus
 * make in Projects. The four states, in order:
 *
 *   open              raised, correction not yet started
 *   under_correction  the responsible party is fixing it
 *   verified          QC re-inspected and the correction is accepted
 *   closed            administratively closed
 *
 * THE BLOCK reads isOpen(): an OPEN NCR (open | under_correction) at a location
 * refuses a later-stage inspection there, and blocks BAST I on that project.
 * `verified` and `closed` no longer block — once QC has accepted the correction,
 * the nonconformance is resolved and work may proceed. This one predicate is the
 * whole meaning of "open NCR" wherever the phrase appears (InspectionService,
 * BastPrerequisiteService reads the same two strings behind Schema::hasTable
 * because Projects may not depend on Quality).
 */
enum NcrStatus: string
{
    case Open = 'open';
    case UnderCorrection = 'under_correction';
    case Verified = 'verified';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Terbuka',
            self::UnderCorrection => 'Perbaikan berjalan',
            self::Verified => 'Terverifikasi',
            self::Closed => 'Ditutup',
        };
    }

    /** Still blocking: the correction is not yet verified. */
    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::UnderCorrection;
    }

    /**
     * The two status strings that count as open, for the cross-module reader in
     * Projects (BastPrerequisiteService) that must NOT import this enum — the
     * dependency arrow is Quality → Projects, never the reverse. Kept here so the
     * two readers cannot drift, and asserted equal to isOpen() in the tests.
     *
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isOpen()),
        ));
    }
}
