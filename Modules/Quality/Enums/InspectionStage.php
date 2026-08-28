<?php

namespace Modules\Quality\Enums;

/**
 * P1-QC: the three quality-hold points of a work package, in the order the work
 * passes through them. A template belongs to exactly one stage, and the
 * ordering below is the comparison the NCR block relies on: an OPEN NCR raised
 * at an earlier stage at a location refuses the submit of a LATER-stage
 * inspection at that same location (InspectionService::submit). "before < during
 * < after" is that ordering, made explicit as an integer so the block never
 * depends on the case declaration order or on string comparison.
 */
enum InspectionStage: string
{
    case Before = 'before';
    case During = 'during';
    case After = 'after';

    public function label(): string
    {
        return match ($this) {
            self::Before => 'Sebelum pelaksanaan',
            self::During => 'Saat pelaksanaan',
            self::After => 'Setelah pelaksanaan',
        };
    }

    /** Position on the hold-point line — the only thing the NCR block compares. */
    public function order(): int
    {
        return match ($this) {
            self::Before => 1,
            self::During => 2,
            self::After => 3,
        };
    }

    /**
     * Strictly later on the line. Same stage is NOT later — a same-stage
     * re-inspection while an NCR is open is allowed (the spec: "same stage
     * passes"); only advancing to a later hold point is refused.
     */
    public function isLaterThan(self $other): bool
    {
        return $this->order() > $other->order();
    }
}
